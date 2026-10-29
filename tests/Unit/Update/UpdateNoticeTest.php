<?php

declare(strict_types=1);

namespace Patchline\Tests\Unit\Update;

use Patchline\Cli\Output\GithubFormatter;
use Patchline\Cli\OutputFormat;
use Patchline\Update\UpdateNotice;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(UpdateNotice::class)]
#[UsesClass(GithubFormatter::class)]
final class UpdateNoticeTest extends TestCase
{
    private const INSTALL = 'curl -fsSL '
        . 'https://github.com/pushonltd/magento-patchline/releases/latest/download/install.sh | bash';

    public function test_it_names_the_latest_and_the_current_version(): void
    {
        $notice = $this->notice(null, true, OutputFormat::Text);

        self::assertStringContainsString('patchline 0.0.3 is available (you have 0.0.2).', $notice);
    }

    public function test_it_tells_a_composer_install_to_require_the_latest_version_with_a_caret_constraint(): void
    {
        $notice = $this->notice(null, true, OutputFormat::Text);

        self::assertStringContainsString(
            'Update with: composer require --dev pushonltd/magento-patchline:^0.0.3',
            $notice,
        );
    }

    public function test_it_omits_dev_for_a_composer_install_that_is_not_a_dev_requirement(): void
    {
        $notice = $this->notice(null, false, OutputFormat::Text);

        self::assertStringContainsString('Update with: composer require pushonltd/magento-patchline:^0.0.3', $notice);
    }

    public function test_it_tells_a_phar_install_in_the_working_directory_to_rerun_the_install_script(): void
    {
        $notice = $this->notice('/work/patchline.phar', true, OutputFormat::Text);

        self::assertSame(
            'patchline 0.0.3 is available (you have 0.0.2). Update with: ' . self::INSTALL . "\n",
            $notice,
        );
    }

    public function test_it_passes_the_path_of_a_phar_installed_elsewhere_to_the_install_script_shell_escaped(): void
    {
        $notice = $this->notice("/opt/my tools/patchline.phar", true, OutputFormat::Text);

        self::assertStringContainsString(
            'Update with: ' . self::INSTALL . " -s -- --path='/opt/my tools/patchline.phar'\n",
            $notice,
        );
    }

    public function test_it_renders_a_github_notice_workflow_command_for_the_github_format(): void
    {
        $notice = $this->notice(null, true, OutputFormat::Github);

        self::assertSame(
            '::notice title=Patchline update::patchline 0.0.3 is available (you have 0.0.2). '
            . "Update with: composer require --dev pushonltd/magento-patchline:^0.0.3\n",
            $notice,
        );
    }

    #[DataProvider('otherFormats')]
    public function test_it_renders_a_single_plain_line_ending_with_a_newline_for_the_other_formats(
        OutputFormat $format,
    ): void {
        $notice = $this->notice(null, true, $format);

        self::assertSame(1, substr_count($notice, "\n"));
        self::assertStringEndsWith("\n", $notice);
        self::assertStringStartsWith('patchline 0.0.3', $notice);
    }

    private function notice(?string $pharPath, bool $dev, OutputFormat $format): string
    {
        return UpdateNotice::render('0.0.2', '0.0.3', $pharPath, '/work', $dev, $format);
    }

    /** @return array<string, array{OutputFormat}> */
    public static function otherFormats(): array
    {
        return ['text' => [OutputFormat::Text], 'json' => [OutputFormat::Json], 'junit' => [OutputFormat::Junit]];
    }

    public function test_it_escapes_workflow_command_special_characters_in_the_github_notice(): void
    {
        $notice = $this->notice("/opt/a%b\r\nc/patchline.phar", true, OutputFormat::Github);

        self::assertStringContainsString("a%25b%0D%0Ac", $notice);
        self::assertSame(1, substr_count($notice, "\n"));
    }
}
