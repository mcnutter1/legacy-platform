; Managed by McNutt Legacy deploy (deploy/server/fpm-pool.conf.tpl) — dedicated pool; do not edit by hand.
[@@APP_SLUG@@]
user = @@RUN_AS@@
group = @@RUN_AS@@
listen = @@FPM_SOCK@@
listen.owner = @@RUN_AS@@
listen.group = @@WEB_GROUP@@
listen.mode = 0660
pm = ondemand
pm.max_children = 8
pm.process_idle_timeout = 30s
pm.max_requests = 500
request_terminate_timeout = 60s
catch_workers_output = yes
clear_env = yes
php_admin_value[error_log] = @@LOG_DIR@@/php-fpm.log
php_admin_flag[log_errors] = on
php_admin_flag[display_errors] = off
php_admin_flag[expose_php] = off
php_admin_value[memory_limit] = 256M
php_admin_value[upload_max_filesize] = 26M
php_admin_value[post_max_size] = 30M
php_admin_value[max_execution_time] = 55
php_admin_value[max_input_vars] = 2000
php_admin_value[session.cookie_secure] = 1
php_admin_value[open_basedir] = @@OPT_DIR@@/:@@DATA_DIR@@/:@@ETC_DIR@@/:@@LOG_DIR@@/:/tmp/:/usr/share/zoneinfo/
; Web requests never start processes: calculators, scanning and extraction run in CLI workers.
php_admin_value[disable_functions] = exec,passthru,shell_exec,system,proc_open,popen,pcntl_exec,pcntl_fork
php_admin_value[upload_tmp_dir] = /tmp
