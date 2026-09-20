# BookSphere — Production Deployment Guide

## 1. Server Prerequisites
- **Web Server**: Nginx or Apache 2.4+
- **PHP**: PHP 8.2+ with `pdo_sqlite`, `curl`, `mbstring`, `json`, `fileinfo`
- **Database**: SQLite 3.39+

## 2. Web Server Configuration

### Nginx Virtual Host Configuration:
```nginx
server {
    listen 80;
    server_name booksphere.example.com;
    root /var/www/booksphere/public;

    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

## 3. Deployment Packaging via `git archive`
BookSphere uses `.gitattributes` to exclude tests, test databases, and scratch scripts from production release archives:
```bash
git archive --format=tar.gz -o booksphere_release.tar.gz HEAD
```
