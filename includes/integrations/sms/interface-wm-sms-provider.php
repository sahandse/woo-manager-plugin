<?php
defined('ABSPATH') || exit;
interface WM_SMS_Provider { public function send(string $mobile, string $message); public function is_configured(): bool; }
