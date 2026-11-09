<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Keeps tokens and passwords out of what agents write: they paste command
 * output (.env lines, Authorization headers, URLs with a password) far more
 * often than people do. Known token shapes, JWTs, bearer, token and basic
 * auth, a password in a URL, and assignments like DB_PASSWORD=..., X-Api-Key:
 * or "password": ... with a long value made of letters and digits. A
 * reference to a secret passes ("${DB_PASSWORD}", "<from vault>", a vault
 * link or item id, a path to a secret file), and so do dates and words
 * joined by hyphens. A guard against accidents, not against an agent that
 * means to get one through. The message never repeats the match.
 */
class NoCredentials implements ValidationRule
{
    private const string BOUNDARY = '(?<![A-Za-z0-9_\-])';

    /**
     * @var list<string>
     */
    private const array PATTERNS = [
        // GitHub, GitLab, Slack, Stripe, AWS, Google, OpenAI/Anthropic, Herdbook's own.
        '/'.self::BOUNDARY.'(?:gh[pousr]_[A-Za-z0-9]{36,}|github_pat_[A-Za-z0-9_]{80,})/',
        '/'.self::BOUNDARY.'glpat-[A-Za-z0-9_\-]{20,}/',
        '/'.self::BOUNDARY.'xox[abposr]-[A-Za-z0-9\-]{10,}/',
        '/'.self::BOUNDARY.'[sr]k_live_[A-Za-z0-9]{20,}/',
        '/'.self::BOUNDARY.'(?:AKIA|ASIA)[0-9A-Z]{16}(?![0-9A-Z])/',
        '/'.self::BOUNDARY.'AIza[0-9A-Za-z_\-]{35}/',
        // With capitals and digits: "sk-prod-cluster-eu-west-1" is a name.
        '/'.self::BOUNDARY.'sk-(?:ant-|proj-)?(?=[A-Za-z0-9_\-]*[A-Z])(?=[A-Za-z0-9_\-]*\d)[A-Za-z0-9_\-]{32,}/',
        '/'.self::BOUNDARY.'hb_[A-Za-z0-9]{48}(?![A-Za-z0-9])/',
        // A JWT.
        '/eyJ[A-Za-z0-9_\-]{8,}\.eyJ[A-Za-z0-9_\-]{8,}\.[A-Za-z0-9_\-]{16,}/',
        // Authorization: Bearer, Token or Basic with a long value; Basic alone when it is padded base64.
        '/\bBearer\s+(?=[A-Za-z0-9._~+\/\-]*\d)[A-Za-z0-9._~+\/\-]{24,}=*/',
        '/\bAuthorization:\s*(?:Basic|Token)\s+[A-Za-z0-9+\/._~\-]{16,}={0,2}/i',
        '/\bBasic\s+[A-Za-z0-9+\/]{16,}={1,2}/',
        // scheme://user:password@host, but not a placeholder as the password.
        '~[a-z][a-z0-9+.\-]*://[^\s/:@]*:(?![$<*{%])[^\s/@]{4,}@~i',
        // DB_PASSWORD=..., SECRET_KEY_BASE=..., X-Api-Key: ..., "password": ...,
        // password => ...: a name made of parts joined by _ or -, one of which
        // says secret (PASS and PWD only as capitals: "passed", "pwd" are words),
        // with a value of 16+ characters, letters and digits. Not a reference
        // to a secret (a variable, a placeholder, a vault link or item id, a
        // path), not a date, not words joined by hyphens.
        '/\b(?:[A-Z0-9]+[_-])*(?:(?-i:PASS|PWD)|PASSWORD|PASSWD|SECRET|TOKEN|(?:API|ACCESS|APP|SECRET|MASTER|ENCRYPTION|SIGNING|PRIVATE)[_-]?KEY)(?:[_-][A-Z0-9]+)*\b["\']?\s*(?:=>|[:=])\s*["\']?'
            .'(?![$<{*%])(?!https?:\/\/)(?![\/~.])(?![^\s"\']*\/[^\s"\']*\/)(?!\d{4}-\d{2}-\d{2})'
            .'(?![0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}(?![\w-]))(?-i:(?![a-z]+(?:-[a-z0-9]+)+(?![^\s"\'])))'
            .'(?=[^\s"\']{16,})(?=[^\s"\']*\d)(?=[^\s"\']*[A-Za-z])[^\s"\']++/i',
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        foreach (self::PATTERNS as $pattern) {
            // A pattern that fails to run refuses: better a retry than a token in the database.
            if (preg_match($pattern, $value) !== 0) {
                $fail(__('This looks like a password or token. Secrets belong in Vaultwarden, not here; refer to them instead.'));

                return;
            }
        }
    }
}
