<?php

declare(strict_types=1);

namespace Tests\Feature;

use Laravel\Boost\Rules\RuleRepository;
use Tests\TestCase;

class BoostRulesIndexTest extends TestCase
{
    public function test_boost_rules_directory_and_index_exist(): void
    {
        /** @var RuleRepository $repository */
        $repository = app(RuleRepository::class);

        $this->assertTrue($repository->exists());
        $this->assertFileExists(base_path('.ai/rules/index.md'));
    }

    public function test_python_rules_are_indexed_with_correct_globs(): void
    {
        $indexPath = base_path('.ai/rules/index.md');
        $indexContent = (string) file_get_contents($indexPath);

        $this->assertStringContainsString('.ai/rules/python-runtime.md', $indexContent);
        $this->assertStringContainsString('.ai/rules/python-security.md', $indexContent);
        $this->assertStringContainsString('app/Services/Python/**', $indexContent);
    }

    public function test_boost_index_rules_command_executes_successfully(): void
    {
        $this->artisan('boost:index-rules')
            ->expectsOutputToContain('Regenerated')
            ->assertSuccessful();
    }
}

