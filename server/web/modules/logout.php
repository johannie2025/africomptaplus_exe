<?php
function mod_logout(): void { web_session(); session_unset(); session_destroy(); redirect(url('login')); }
