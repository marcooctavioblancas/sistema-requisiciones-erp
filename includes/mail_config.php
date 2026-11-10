<?php
/**
 * Configuración SMTP (Brevo, Gmail, Mailgun, etc.)
 * Los valores reales viven en .env (ver .env.example) y llegan como variables
 * de entorno; nunca se escriben en este archivo.
 */
define('SMTP_HOST',      getenv('SMTP_HOST') ?: '');
define('SMTP_PORT',      (int) (getenv('SMTP_PORT') ?: 587));
define('SMTP_USER',      getenv('SMTP_USER') ?: '');
define('SMTP_PASS',      getenv('SMTP_PASS') ?: '');
define('SMTP_FROM',      getenv('SMTP_FROM') ?: '');
define('SMTP_FROM_NAME', defined('SITE_NAME') ? SITE_NAME : 'Sistema de Requisiciones');
