//go:build windows

// AfriCompta+ — lanceur portable Windows.
// Démarre MariaDB + PHP (serveur intégré) en local (127.0.0.1) puis ouvre l'app
// dans une fenêtre dédiée (Edge/Chrome en mode --app). À la fermeture : arrêt propre.
package main

import (
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"hash/fnv"
	"io"
	"net"
	"net/http"
	"os"
	"os/exec"
	"path/filepath"
	"strconv"
	"strings"
	"syscall"
	"time"
	"unsafe"
)

const (
	webPortPref    = 18765
	dbPortPref     = 33765
	createNoWindow = 0x08000000
)

type secrets struct {
	DBPass    string `json:"db_pass"`
	AppSecret string `json:"app_secret"`
}
type runInfo struct {
	WebPort int `json:"web_port"`
}

var (
	base, dataDir string
	selftest      bool
	openAdmin     bool
	phpCmd, dbCmd *exec.Cmd
	dbDone        chan struct{}
	dbPort        int
	dbBin         string
	secret        secrets

	user32       = syscall.NewLazyDLL("user32.dll")
	kernel32     = syscall.NewLazyDLL("kernel32.dll")
	pMessageBox  = user32.NewProc("MessageBoxW")
	pCreateMutex = kernel32.NewProc("CreateMutexW")
	pCreateJob   = kernel32.NewProc("CreateJobObjectW")
	pSetJobInfo  = kernel32.NewProc("SetInformationJobObject")
	pAssignJob   = kernel32.NewProc("AssignProcessToJobObject")
	job          uintptr
)

// ── Windows helpers ─────────────────────────────────────────────────────────
func msgBox(title, text string, flags uintptr) {
	t, _ := syscall.UTF16PtrFromString(text)
	c, _ := syscall.UTF16PtrFromString(title)
	pMessageBox.Call(0, uintptr(unsafe.Pointer(t)), uintptr(unsafe.Pointer(c)), flags)
}

func alreadyRunning(name string) bool {
	n, _ := syscall.UTF16PtrFromString(name)
	h, _, err := pCreateMutex.Call(0, 0, uintptr(unsafe.Pointer(n)))
	if h == 0 {
		return false
	}
	return err == syscall.Errno(183) // ERROR_ALREADY_EXISTS
}

type jobBasic struct {
	PerProcessUserTimeLimit int64
	PerJobUserTimeLimit     int64
	LimitFlags              uint32
	MinimumWorkingSetSize   uintptr
	MaximumWorkingSetSize   uintptr
	ActiveProcessLimit      uint32
	Affinity                uintptr
	PriorityClass           uint32
	SchedulingClass         uint32
}
type ioCounters struct{ a, b, c, d, e, f uint64 }
type jobExt struct {
	Basic                 jobBasic
	Io                    ioCounters
	ProcessMemoryLimit    uintptr
	JobMemoryLimit        uintptr
	PeakProcessMemoryUsed uintptr
	PeakJobMemoryUsed     uintptr
}

// Si le lanceur est tué (gestionnaire des tâches), Windows tue aussi PHP et MariaDB (pas d'orphelins).
func initJob() {
	h, _, _ := pCreateJob.Call(0, 0)
	if h == 0 {
		return
	}
	var info jobExt
	info.Basic.LimitFlags = 0x2000 // JOB_OBJECT_LIMIT_KILL_ON_JOB_CLOSE
	r, _, _ := pSetJobInfo.Call(h, 9, uintptr(unsafe.Pointer(&info)), unsafe.Sizeof(info))
	if r != 0 {
		job = h
	}
}
func addToJob(p *os.Process) {
	if job == 0 || p == nil {
		return
	}
	h, err := syscall.OpenProcess(0x0101, false, uint32(p.Pid)) // SET_QUOTA | TERMINATE
	if err != nil {
		return
	}
	pAssignJob.Call(job, uintptr(h))
	syscall.CloseHandle(h)
}
func hide(c *exec.Cmd) {
	c.SysProcAttr = &syscall.SysProcAttr{HideWindow: true, CreationFlags: createNoWindow}
}

// ── utilitaires ─────────────────────────────────────────────────────────────
func exists(p string) bool { _, err := os.Stat(p); return err == nil }

func logFile(name string) *os.File {
	f, err := os.OpenFile(filepath.Join(dataDir, "logs", name), os.O_APPEND|os.O_CREATE|os.O_WRONLY, 0o644)
	if err != nil {
		return nil
	}
	return f
}
func appendLog(name string, b []byte) {
	if f := logFile(name); f != nil {
		f.Write(b)
		f.Close()
	}
}
func fail(msg string) {
	appendLog("launcher.log", []byte(time.Now().Format(time.RFC3339)+" "+msg+"\n"))
	if selftest {
		os.WriteFile(filepath.Join(dataDir, "selftest.txt"), []byte("FAIL: "+msg), 0o644)
		return
	}
	msgBox("AfriCompta+", msg, 0x10)
}
func die(msg string) { fail(msg); shutdown(); os.Exit(1) }

func tailStr(s string, n int) string {
	if len(s) > n {
		return "…" + s[len(s)-n:]
	}
	return s
}
func randHex(n int) string {
	b := make([]byte, n)
	rand.Read(b)
	return hex.EncodeToString(b)
}
func hasNonASCII(s string) bool {
	for _, r := range s {
		if r > 127 {
			return true
		}
	}
	return false
}
func pickPort(pref int) int {
	for p := pref; p < pref+50; p++ {
		if l, err := net.Listen("tcp", "127.0.0.1:"+strconv.Itoa(p)); err == nil {
			l.Close()
			return p
		}
	}
	l, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		return pref
	}
	defer l.Close()
	return l.Addr().(*net.TCPAddr).Port
}
func waitPort(port int, d time.Duration, abort <-chan struct{}) bool {
	deadline := time.Now().Add(d)
	for time.Now().Before(deadline) {
		if abort != nil {
			select {
			case <-abort:
				return false
			default:
			}
		}
		if c, err := net.DialTimeout("tcp", fmt.Sprintf("127.0.0.1:%d", port), 500*time.Millisecond); err == nil {
			c.Close()
			return true
		}
		time.Sleep(300 * time.Millisecond)
	}
	return false
}
func loadSecrets() secrets {
	p := filepath.Join(dataDir, "secrets.json")
	var s secrets
	if b, err := os.ReadFile(p); err == nil && json.Unmarshal(b, &s) == nil && s.DBPass != "" && s.AppSecret != "" {
		return s
	}
	s = secrets{DBPass: randHex(16), AppSecret: randHex(32)}
	b, _ := json.Marshal(s)
	os.WriteFile(p, b, 0o600)
	return s
}

// ── configuration générée à chaque démarrage (chemins absolus → dossier déplaçable) ──
func writeIni() string {
	p := filepath.Join(dataDir, "php.ini")
	s := filepath.ToSlash
	ini := fmt.Sprintf(`[PHP]
extension_dir="%s"
extension=mbstring
extension=openssl
extension=curl
extension=pdo_mysql
extension=mysqli
extension=fileinfo
display_errors=Off
log_errors=On
error_log="%s"
memory_limit=256M
max_execution_time=120
upload_max_filesize=20M
post_max_size=24M
date.timezone=UTC
default_charset="UTF-8"
session.save_path="%s"
session.gc_maxlifetime=28800
`, s(filepath.Join(base, "php", "ext")), s(filepath.Join(dataDir, "logs", "php.log")), s(filepath.Join(dataDir, "sessions")))
	os.WriteFile(p, []byte(ini), 0o644)
	return p
}
func writeConfig() error {
	cfg := fmt.Sprintf("<?php return ['db_dsn'=>'mysql:host=127.0.0.1;port=%d;dbname=africompta;charset=utf8mb4','db_user'=>'root','db_pass'=>'%s','app_secret'=>'%s','session_timeout'=>28800];\n",
		dbPort, secret.DBPass, secret.AppSecret)
	return os.WriteFile(filepath.Join(base, "app", "inc", "config.local.php"), []byte(cfg), 0o600)
}

// ── MariaDB ─────────────────────────────────────────────────────────────────
func installDB() error {
	dbData := filepath.Join(dataDir, "mysql")
	os.RemoveAll(dbData)
	os.Remove(filepath.Join(dataDir, ".schema_hash"))
	cmd := exec.Command(filepath.Join(dbBin, "mysql_install_db.exe"),
		"--datadir="+dbData, "--password="+secret.DBPass, "--port="+strconv.Itoa(dbPort))
	hide(cmd)
	out, err := cmd.CombinedOutput()
	appendLog("install_db.log", out)
	if err != nil {
		return fmt.Errorf("%v: %s", err, tailStr(string(out), 500))
	}
	return nil
}
func startDB() error {
	dbData := filepath.Join(dataDir, "mysql")
	var args []string
	if ini := filepath.Join(dbData, "my.ini"); exists(ini) {
		args = append(args, "--defaults-file="+ini) // doit être le 1er argument
	}
	args = append(args, "--datadir="+dbData, "--port="+strconv.Itoa(dbPort), "--bind-address=127.0.0.1",
		"--console", "--default-time-zone=+00:00", "--character-set-server=utf8mb4",
		"--collation-server=utf8mb4_unicode_ci", "--max-connections=40", "--innodb-buffer-pool-size=64M")
	cmd := exec.Command(filepath.Join(dbBin, "mysqld.exe"), args...)
	if lf := logFile("mysql.log"); lf != nil {
		cmd.Stdout, cmd.Stderr = lf, lf
	}
	hide(cmd)
	if err := cmd.Start(); err != nil {
		return err
	}
	addToJob(cmd.Process)
	dbCmd = cmd
	dbDone = make(chan struct{})
	go func() { cmd.Wait(); close(dbDone) }()
	return nil
}
func stopDB() {
	if dbCmd == nil || dbCmd.Process == nil {
		return
	}
	select {
	case <-dbDone:
		return
	default:
	}
	for _, n := range []string{"mariadb-admin.exe", "mysqladmin.exe"} {
		if adm := filepath.Join(dbBin, n); exists(adm) {
			c := exec.Command(adm, "--user=root", "--host=127.0.0.1", "--port="+strconv.Itoa(dbPort), "--protocol=tcp", "shutdown")
			c.Env = append(os.Environ(), "MYSQL_PWD="+secret.DBPass)
			hide(c)
			go c.Run()
			break
		}
	}
	select {
	case <-dbDone:
	case <-time.After(30 * time.Second):
		dbCmd.Process.Kill()
	}
}
func runSetup(php, ini, appDir string) (bool, error) {
	cmd := exec.Command(php, "-c", ini, filepath.Join(base, "runtime", "setup.php"))
	cmd.Env = append(os.Environ(), "ACP_DB_PORT="+strconv.Itoa(dbPort), "ACP_DB_PASS="+secret.DBPass,
		"ACP_APP_DIR="+appDir, "ACP_DATA_DIR="+dataDir)
	hide(cmd)
	out, err := cmd.CombinedOutput()
	appendLog("setup.log", out)
	if err != nil {
		return false, fmt.Errorf("%v: %s", err, tailStr(string(out), 600))
	}
	return strings.Contains(string(out), "FIRST_RUN=1"), nil
}

// ── fenêtre ─────────────────────────────────────────────────────────────────
func browserPath() string {
	pf, pf86, la := os.Getenv("ProgramFiles"), os.Getenv("ProgramFiles(x86)"), os.Getenv("LocalAppData")
	for _, c := range []string{
		pf86 + `\Microsoft\Edge\Application\msedge.exe`, pf + `\Microsoft\Edge\Application\msedge.exe`,
		pf + `\Google\Chrome\Application\chrome.exe`, pf86 + `\Google\Chrome\Application\chrome.exe`,
		la + `\Google\Chrome\Application\chrome.exe`,
	} {
		if strings.HasPrefix(c, `\`) {
			continue
		}
		if exists(c) {
			return c
		}
	}
	return ""
}
func launchBrowser(url string) *exec.Cmd {
	b := browserPath()
	if b == "" {
		return nil
	}
	cmd := exec.Command(b, "--app="+url, "--user-data-dir="+filepath.Join(dataDir, "browser"),
		"--no-first-run", "--no-default-browser-check", "--disable-background-mode", "--window-size=1280,860")
	if cmd.Start() != nil {
		return nil
	}
	return cmd
}
func blockUntilUser() {
	msgBox("AfriCompta+", "AfriCompta+ est en cours d'exécution.\n\nCliquez sur OK pour fermer l'application et arrêter les services.", 0x40)
}

func shutdown() {
	if phpCmd != nil && phpCmd.Process != nil {
		phpCmd.Process.Kill()
	}
	stopDB()
	os.Remove(filepath.Join(dataDir, "run.json"))
}

// ── auto-test (CI) ──────────────────────────────────────────────────────────
func runSelftest(port int) error {
	cl := &http.Client{Timeout: 30 * time.Second, CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse }}
	get := func(path string) (int, string, error) {
		r, err := cl.Get(fmt.Sprintf("http://127.0.0.1:%d%s", port, path))
		if err != nil {
			return 0, "", err
		}
		defer r.Body.Close()
		b, _ := io.ReadAll(r.Body)
		return r.StatusCode, string(b), nil
	}
	if c, b, err := get("/__health"); err != nil || c != 200 || !strings.Contains(b, "ok") {
		return fmt.Errorf("/__health : code=%d err=%v body=%s", c, err, tailStr(b, 300))
	}
	if c, b, err := get("/web/index.php"); err != nil || c != 200 || !strings.Contains(b, "AfriCompta") {
		return fmt.Errorf("/web/index.php : code=%d err=%v", c, err)
	}
	if c, _, err := get("/web/assets/app.css"); err != nil || c != 200 {
		return fmt.Errorf("/web/assets/app.css : code=%d err=%v", c, err)
	}
	if c, _, _ := get("/web/inc/boot.php"); c != 403 {
		return fmt.Errorf("/web/inc/boot.php devrait être 403, reçu %d", c)
	}
	if c, _, err := get("/admin/login.php"); err != nil || c >= 400 {
		return fmt.Errorf("/admin/login.php : code=%d err=%v", c, err)
	}
	return nil
}

func main() {
	exe, _ := os.Executable()
	base = filepath.Dir(exe)
	for _, a := range os.Args[1:] {
		switch a {
		case "--selftest":
			selftest = true
		case "--admin":
			openAdmin = true
		}
	}
	dataDir = os.Getenv("ACP_DATA")
	if dataDir == "" {
		dataDir = filepath.Join(base, "data")
	}
	for _, d := range []string{"logs", "sessions", "browser", "tmp"} {
		os.MkdirAll(filepath.Join(dataDir, d), 0o755)
	}

	h := fnv.New32a()
	h.Write([]byte(strings.ToLower(base)))
	if alreadyRunning(fmt.Sprintf("Local\\AfriComptaPortable_%x", h.Sum32())) && !selftest {
		if b, err := os.ReadFile(filepath.Join(dataDir, "run.json")); err == nil {
			var r runInfo
			if json.Unmarshal(b, &r) == nil && r.WebPort > 0 {
				p := "/web/index.php"
				if openAdmin {
					p = "/admin/"
				}
				launchBrowser(fmt.Sprintf("http://127.0.0.1:%d%s", r.WebPort, p))
			}
		}
		return
	}

	phpExe := filepath.Join(base, "php", "php.exe")
	dbBin = filepath.Join(base, "mariadb", "bin")
	appDir := filepath.Join(base, "app")
	for _, f := range []string{phpExe, filepath.Join(dbBin, "mysqld.exe"), filepath.Join(dbBin, "mysql_install_db.exe"), filepath.Join(appDir, "web", "index.php")} {
		if !exists(f) {
			die("Fichier manquant : " + f + "\n\nRé-extrayez l'archive complète (ne déplacez pas AfriCompta.exe seul).")
		}
	}
	marker := filepath.Join(dataDir, ".db_ready")
	if !exists(marker) && hasNonASCII(base) && !selftest {
		msgBox("AfriCompta+", "Le chemin du dossier contient des caractères accentués ou spéciaux :\n"+base+"\n\nMariaDB peut refuser de démarrer. Si une erreur apparaît, déplacez le dossier vers un chemin simple (ex. C:\\AfriCompta).", 0x30)
	}

	initJob()
	secret = loadSecrets()
	dbPort = pickPort(dbPortPref)
	webPort := pickPort(webPortPref)
	ini := writeIni()
	if err := writeConfig(); err != nil {
		die("Impossible d'écrire la configuration (dossier en lecture seule ?) : " + err.Error())
	}

	if !exists(marker) {
		if err := installDB(); err != nil {
			die("Initialisation de la base de données impossible :\n" + err.Error() + "\n\nJournaux : " + filepath.Join(dataDir, "logs"))
		}
	}
	if err := startDB(); err != nil {
		die("Démarrage de MariaDB impossible : " + err.Error())
	}
	if !waitPort(dbPort, 90*time.Second, dbDone) {
		die("MariaDB ne démarre pas.\nVoir : " + filepath.Join(dataDir, "logs", "mysql.log"))
	}
	firstRun, err := runSetup(phpExe, ini, appDir)
	if err != nil {
		die("Préparation de la base impossible :\n" + err.Error() + "\n\nJournaux : " + filepath.Join(dataDir, "logs"))
	}
	os.WriteFile(marker, []byte(time.Now().Format(time.RFC3339)), 0o644)

	phpCmd = exec.Command(phpExe, "-c", ini, "-S", fmt.Sprintf("127.0.0.1:%d", webPort), "-t", appDir, filepath.Join(base, "runtime", "router.php"))
	phpCmd.Dir = appDir
	if lf := logFile("php-server.log"); lf != nil {
		phpCmd.Stdout, phpCmd.Stderr = lf, lf
	}
	hide(phpCmd)
	if err := phpCmd.Start(); err != nil {
		die("Démarrage de PHP impossible : " + err.Error())
	}
	addToJob(phpCmd.Process)
	if !waitPort(webPort, 30*time.Second, nil) {
		die("Le serveur PHP ne répond pas.\nVoir : " + filepath.Join(dataDir, "logs"))
	}
	b, _ := json.Marshal(runInfo{WebPort: webPort})
	os.WriteFile(filepath.Join(dataDir, "run.json"), b, 0o644)

	if selftest {
		res := "OK"
		code := 0
		if err := runSelftest(webPort); err != nil {
			res, code = "FAIL: "+err.Error(), 1
		}
		os.WriteFile(filepath.Join(dataDir, "selftest.txt"), []byte(res), 0o644)
		shutdown()
		os.Exit(code)
	}

	path := "/web/index.php"
	if firstRun || openAdmin {
		path = "/admin/" // 1er lancement : installation de l'administrateur, puis création de la licence
		if firstRun {
			path = "/admin/install.php"
		}
	}
	url := fmt.Sprintf("http://127.0.0.1:%d%s", webPort, path)
	started := time.Now()
	if bc := launchBrowser(url); bc != nil {
		bc.Wait()
		if time.Since(started) < 6*time.Second { // le navigateur s'est délégué à un autre processus
			blockUntilUser()
		}
	} else {
		exec.Command("rundll32", "url.dll,FileProtocolHandler", url).Start()
		blockUntilUser()
	}
	shutdown()
}
