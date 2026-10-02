<?php

namespace App\Services;

use App\Models\Company;
use App\Models\MailLog;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;

/**
 * Sends through the company's own SMTP when configured, otherwise (or if that
 * fails) through the platform mailer from .env. Every attempt lands in mail_logs.
 *
 * Note: an accepted SMTP transaction proves the server took the message, not
 * that it reached the inbox — check real delivery when changing mail settings.
 */
class MailService
{
    public function send(?Company $company, string $to, Mailable $mail, string $kind, ?int $userId = null): bool
    {
        $subject = $this->subjectOf($mail);

        if ($company?->hasOwnSmtp()) {
            try {
                $this->companyMailer($company)->to($to)->send($mail);
                $this->log($company, $userId, $to, $subject, $kind, 'sent', null, 'company');

                return true;
            } catch (\Throwable $e) {
                $this->log($company, $userId, $to, $subject, $kind, 'failed', $this->short($e), 'company');
            }
        }

        try {
            Mail::mailer()->to($to)->send($mail);
            $this->log($company, $userId, $to, $subject, $kind, 'sent', null, 'platform');

            return true;
        } catch (\Throwable $e) {
            $this->log($company, $userId, $to, $subject, $kind, 'failed', $this->short($e), 'platform');

            return false;
        }
    }

    /** Sends strictly via the company SMTP (no fallback) so the settings page can test it. */
    public function test(Company $company, string $to, Mailable $mail): ?string
    {
        try {
            $this->companyMailer($company)->to($to)->send($mail);
            $this->log($company, auth()->id(), $to, $this->subjectOf($mail), 'test', 'sent', null, 'company');

            return null;
        } catch (\Throwable $e) {
            $this->log($company, auth()->id(), $to, $this->subjectOf($mail), 'test', 'failed', $this->short($e), 'company');

            return $this->short($e);
        }
    }

    public function companyMailer(Company $company): Mailer
    {
        $scheme = match ($company->smtp_encryption) {
            'ssl' => 'smtps',
            default => 'smtp', // STARTTLS is negotiated automatically on 587
        };

        $mailer = Mail::build([
            'transport' => 'smtp',
            'scheme' => $scheme,
            'host' => $company->smtp_host,
            'port' => $company->smtp_port ?: ($scheme === 'smtps' ? 465 : 587),
            'username' => $company->smtp_username,
            'password' => $company->smtp_password,
            'timeout' => 20,
        ]);
        $mailer->alwaysFrom($company->smtp_from_address, $company->smtp_from_name ?: $company->name);

        return $mailer;
    }

    private function subjectOf(Mailable $mail): string
    {
        if (method_exists($mail, 'envelope')) {
            return (string) $mail->envelope()->subject;
        }

        return (string) ($mail->subject ?? class_basename($mail));
    }

    private function short(\Throwable $e): string
    {
        return mb_substr(class_basename($e).': '.$e->getMessage(), 0, 1000);
    }

    private function log(?Company $company, ?int $userId, string $to, string $subject, string $kind, string $status, ?string $error, string $transport): void
    {
        MailLog::withoutGlobalScopes()->create([
            'company_id' => $company?->id,
            'user_id' => $userId,
            'to' => $to,
            'subject' => mb_substr($subject, 0, 250),
            'kind' => $kind,
            'status' => $status,
            'error' => $error,
            'transport' => $transport,
        ]);
    }
}
