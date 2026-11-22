<VirtualHost *:443>
    ServerName @@DOMAIN@@
    DocumentRoot @@OPT_DIR@@/current/public
    Alias /.well-known/acme-challenge/ @@ACME_ROOT@@/.well-known/acme-challenge/
    <Directory @@ACME_ROOT@@/.well-known/acme-challenge>
        Options None
        AllowOverride None
        Require all granted
    </Directory>
    SSLEngine on
    SSLCertificateFile @@CERT_DIR@@/fullchain.pem
    SSLCertificateKeyFile @@CERT_DIR@@/privkey.pem
    SSLProtocol -all +TLSv1.2 +TLSv1.3
    SSLHonorCipherOrder off

    <Directory @@OPT_DIR@@/current/public>
        Options -Indexes -MultiViews +FollowSymLinks
        AllowOverride None
        Require all granted
        DirectoryIndex index.php
        FallbackResource /index.php
    </Directory>
    <DirectoryMatch "/\.(?!well-known)">
        Require all denied
    </DirectoryMatch>
    # Hashed build assets are immutable.
    <LocationMatch "^/build/assets/">
        Header set Cache-Control "public, max-age=31536000, immutable"
    </LocationMatch>
    <FilesMatch "\.php$">
        SetHandler "proxy:unix:@@FPM_SOCK@@|fcgi://@@APP_SLUG@@/"
    </FilesMatch>
    <Proxy "fcgi://@@APP_SLUG@@/">
        ProxySet timeout=65
    </Proxy>
    # Uploads: vault 25 MB + multipart overhead. MCP/JSON bodies are much smaller and limited in the app.
    LimitRequestBody 31457280
    ServerSignature Off
    Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"
    Header always unset X-Powered-By

    # Never log bearer links or OAuth/SSO callback data: query strings are dropped everywhere,
    # and token-bearing paths are written with the token replaced.
    SetEnvIf Request_URI "^/(d|x|u|invite)/[A-Za-z0-9]{40,}" @@APP_TAG@@_redact
    ErrorLog ${APACHE_LOG_DIR}/@@DOMAIN@@-error.log
    CustomLog ${APACHE_LOG_DIR}/@@DOMAIN@@-access.log @@APP_TAG@@_safe env=!@@APP_TAG@@_redact
    CustomLog ${APACHE_LOG_DIR}/@@DOMAIN@@-access.log @@APP_TAG@@_redacted env=@@APP_TAG@@_redact
</VirtualHost>
