<?php

namespace InertiaPixel\LaravelLogViewer\Tests\Unit;

use InertiaPixel\LaravelLogViewer\Exceptions\InvalidConfigurationException;
use InertiaPixel\LaravelLogViewer\Support\Redactor;
use PHPUnit\Framework\TestCase;

class RedactorTest extends TestCase
{
    private const RULES = [
        'sensitive_keys' => true, 'bearer_tokens' => true, 'jwt' => true, 'app_keys' => true,
        'aws_keys' => true, 'private_keys' => true, 'emails' => false, 'ip_addresses' => false,
    ];

    private function redactor(array $rules = [], array $custom = [], ?string $basePath = null, bool $enabled = true): Redactor
    {
        return new Redactor($enabled, array_merge(self::RULES, $rules), ['password', 'token', 'api_key', 'authorization', 'x-api-key', 'cookie', 'auth', 'cvv'], $custom, '[REDACTED]', $basePath);
    }

    public function test_json_values(): void
    {
        $this->assertSame(
            '{"email":"jane@example.com","password":"[REDACTED]","token":"[REDACTED]","n":1}',
            $this->redactor()->apply('{"email":"jane@example.com","password":"hunter2","token":"abc\"def","n":1}'),
        );

        $this->assertSame('{"password":"[REDACTED]"}', $this->redactor()->apply('{"password":12345}'));
    }

    public function test_json_lists_and_objects_are_masked_whole(): void
    {
        $redactor = $this->redactor();

        $this->assertSame(
            '{"headers":{"cookie":"[REDACTED]","accept":"*/*"}}',
            $redactor->apply('{"headers":{"cookie":["laravel_session=SESSIONSECRET","x=[y]"],"accept":"*/*"}}'),
        );
        $this->assertSame('{"auth":"[REDACTED]","ok":1}', $redactor->apply('{"auth":{"pass":"x","nested":{"deep":"}"}},"ok":1}'));
        $this->assertSame('{"payload":"{\\"cookie\\":\\"[REDACTED]\\"}"}', $redactor->apply('{"payload":"{\\"cookie\\":[\\"SESSIONSECRET\\"]}"}'));
    }

    public function test_truncated_values_are_masked_to_the_end(): void
    {
        $redactor = $this->redactor();

        $this->assertSame('{"user":"jane","password":"[REDACTED]"', $redactor->apply('{"user":"jane","password":"hunter2-and-more'));
        $this->assertSame('{"cookie":"[REDACTED]"', $redactor->apply('{"cookie":["SESSIONSECRET", "oth'));
        $this->assertSame('password=[REDACTED]', $redactor->apply('password="hunter2 truncated'));
    }

    public function test_quoted_and_prefixed_keys(): void
    {
        $redactor = $this->redactor();

        $this->assertSame('password=[REDACTED] user=jane', $redactor->apply('password="hunter 2" user=jane'));
        $this->assertSame("DB_PASSWORD=[REDACTED]\nMAIL_PASSWORD=[REDACTED]", $redactor->apply("DB_PASSWORD=secret\nMAIL_PASSWORD='x y'"));
        $this->assertSame('{"db_password":"[REDACTED]","stripe_api_key":"[REDACTED]"}', $redactor->apply('{"db_password":"a","stripe_api_key":"b"}'));
        $this->assertSame('X-Auth-Token: [REDACTED]', $redactor->apply('X-Auth-Token: abc'));
        $this->assertSame('max_tokens=5 tokenizer=x', $redactor->apply('max_tokens=5 tokenizer=x'), 'Only whole key names match.');
    }

    public function test_nested_print_r_and_var_export(): void
    {
        $redactor = $this->redactor();

        $this->assertSame(
            "Array\n(\n    [cookie] => [REDACTED]\n\n    [name] => Jane\n)",
            $redactor->apply("Array\n(\n    [cookie] => Array\n        (\n            [0] => laravel_session=SECRET\n        )\n\n    [name] => Jane\n)"),
        );
        $this->assertSame(
            "array (\n  'auth' => \n  '[REDACTED]',\n  'name' => 'Jane',\n)",
            $redactor->apply("array (\n  'auth' => \n  array (\n    'pass' => 'SECRET',\n  ),\n  'name' => 'Jane',\n)"),
        );
    }

    public function test_structured_data_is_redacted_by_key(): void
    {
        $data = $this->redactor(basePath: '/var/www/html')->applyToData([
            'Cookie' => ['laravel_session' => 'SECRET'],
            'db_password' => 123,
            'user' => ['name' => 'Jane', 'api_key' => null, 'file' => '/var/www/html/app/x.php', 'note' => 'Bearer abcdefghijklmnop'],
            'tokens_used' => 5,
        ]);

        $this->assertSame([
            'Cookie' => '[REDACTED]',
            'db_password' => '[REDACTED]',
            'user' => ['name' => 'Jane', 'api_key' => '[REDACTED]', 'file' => 'app/x.php', 'note' => 'Bearer [REDACTED]'],
            'tokens_used' => 5,
        ], $data);
    }

    public function test_search_prefilter_is_only_allowed_when_provably_safe(): void
    {
        $redactor = $this->redactor();

        foreach (['timeout', 'User 12', 'Connection.php', 'password'] as $needle) {
            $this->assertFalse($redactor->mayIntroduce($needle), $needle);
        }

        foreach (['[REDACTED]', 'redact', 'D]', 'pin":"', 'x [RED', 'e', 'content withheld'] as $needle) {
            $this->assertTrue($redactor->mayIntroduce($needle), $needle);
        }

        $this->assertTrue($this->redactor([], ['/\d+/' => ''])->mayIntroduce('timeout'), 'Custom replacements are arbitrary.');
    }

    public function test_escaped_json_inside_strings(): void
    {
        $this->assertSame(
            '{"payload":"{\"password\":\"[REDACTED]\",\"x\":1}"}',
            $this->redactor()->apply('{"payload":"{\"password\":\"hunter2\",\"x\":1}"}'),
        );
    }

    public function test_query_strings_print_r_var_export_and_headers(): void
    {
        $redactor = $this->redactor();

        $this->assertSame('GET /login?user=jane&password=[REDACTED]&next=/', $redactor->apply('GET /login?user=jane&password=s3cret&next=/'));
        $this->assertSame("[password] => [REDACTED]\n[name] => Jane", $redactor->apply("[password] => s3cret\n[name] => Jane"));
        $this->assertSame("'api_key' => '[REDACTED]',", $redactor->apply("'api_key' => 'k-123',"));
        $this->assertSame("X-Api-Key: [REDACTED]\nAccept: */*", $redactor->apply("X-Api-Key: abcdef\nAccept: */*"));
        $this->assertSame('Authorization: [REDACTED]', $redactor->apply('Authorization: Bearer abc.def.ghi'));
    }

    public function test_tokens_and_keys(): void
    {
        $redactor = $this->redactor();

        $this->assertSame('sent Bearer [REDACTED] upstream', $redactor->apply('sent Bearer abcdefghijklmnop123 upstream'));
        $this->assertSame('jwt [REDACTED] end', $redactor->apply('jwt eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dozjgNryP4J3jVmNHl0w5N_XgL0n3I9PlFUP0THsR8U end'));
        $this->assertSame('APP_KEY=[REDACTED]', $redactor->apply('APP_KEY=base64:YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWE='));
        $this->assertSame('key [REDACTED]', $redactor->apply('key AKIAIOSFODNN7EXAMPLE'));
        $this->assertSame("before\n[REDACTED]\nafter", $redactor->apply("before\n-----BEGIN RSA PRIVATE KEY-----\nMIIEow\n-----END RSA PRIVATE KEY-----\nafter"));
    }

    public function test_optional_rules_are_off_by_default(): void
    {
        $this->assertSame('jane@example.com from 203.0.113.9', $this->redactor()->apply('jane@example.com from 203.0.113.9'));
        $this->assertSame('[REDACTED] from [REDACTED]', $this->redactor(['emails' => true, 'ip_addresses' => true])->apply('jane@example.com from 203.0.113.9'));
    }

    public function test_custom_patterns(): void
    {
        $redactor = $this->redactor([], ['/\bcust_[a-z0-9]+\b/' => '[CUSTOMER]', '/\b\d{16}\b/']);

        $this->assertSame('[CUSTOMER] paid with [REDACTED]', $redactor->apply('cust_abc123 paid with 4111111111111111'));
    }

    public function test_invalid_custom_patterns_fail_closed(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->redactor([], ['/(unclosed' => 'x']);
    }

    public function test_redaction_can_be_disabled(): void
    {
        $this->assertSame('{"password":"hunter2"}', $this->redactor(enabled: false)->apply('{"password":"hunter2"}'));
    }

    public function test_base_path_is_hidden_in_all_escaped_forms(): void
    {
        $redactor = $this->redactor(basePath: '/var/www/html');

        $this->assertSame('at app/Models/User.php:12', $redactor->apply('at /var/www/html/app/Models/User.php:12'));
        $this->assertSame('{"file":"app\/x.php"}', $redactor->apply('{"file":"\/var\/www\/html\/app\/x.php"}'));
        $this->assertSame('/var/www/other/x.php', $redactor->apply('/var/www/other/x.php'));
    }

    public function test_replacement_text_is_literal(): void
    {
        $redactor = new Redactor(true, self::RULES, ['password'], [], '$1 \\0', null);

        $this->assertSame('password=$1 \\0', $redactor->apply('password=secret'));
    }

    public function test_pcre_failure_withholds_the_text(): void
    {
        $previous = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '10');

        try {
            $redactor = $this->redactor([], ['/(a|aa)+$/' => 'x']);
            $this->assertSame(Redactor::FAILED, $redactor->apply(str_repeat('a', 40).'!'));
        } finally {
            ini_set('pcre.backtrack_limit', (string) $previous);
        }
    }
}
