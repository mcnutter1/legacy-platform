# Managed by McNutt Legacy deploy (deploy/server/vhost.conf.tpl) — do not edit by hand.
<VirtualHost *:80>
    ServerName @@DOMAIN@@
    DocumentRoot @@OPT_DIR@@/current/public
    Alias /.well-known/acme-challenge/ @@ACME_ROOT@@/.well-known/acme-challenge/
    <Directory @@ACME_ROOT@@/.well-known/acme-challenge>
        Options None
        AllowOverride None
        Require all granted
    </Directory>
@@HTTP_BODY@@
    ErrorLog ${APACHE_LOG_DIR}/@@DOMAIN@@-error.log
    CustomLog ${APACHE_LOG_DIR}/@@DOMAIN@@-access.log @@APP_TAG@@_safe
</VirtualHost>
@@HTTPS_BLOCK@@
