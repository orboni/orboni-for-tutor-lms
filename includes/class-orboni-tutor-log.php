<?php

defined('ABSPATH') || exit;

/**
 * The site's PHP error log (wp-content/debug.log with WP_DEBUG_LOG), each
 * line marked "Orboni for Tutor LMS:" — Tutor LMS keeps no log of its own.
 * Requests and webhooks are written while the method's debug log is on;
 * errors always. Never given a key or a signing secret. What happened to an
 * order is in its own history too.
 */
class Orboni_Tutor_Log
{
    public static function info($message)
    {
        if (Orboni_Tutor_Settings::debug()) {
            self::write($message);
        }
    }

    public static function error($message)
    {
        // A merchant asking why crypto failed needs these whatever the setting.
        self::write($message);
    }

    private static function write($message)
    {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- the method's log, as the setting describes; no key or secret is ever in it.
        error_log('Orboni for Tutor LMS: '.$message);
    }
}
