<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\ConfigBundle\Tests\Entity;

use c975L\ConfigBundle\Entity\Config;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Violation\ConstraintViolationBuilderInterface;

class ConfigTest extends TestCase
{
    public function testGetLabelTranslationKeyDerivesFromSlugReplacingDashesWithUnderscores(): void
    {
        $config = (new Config())->setSlug('site-maintenance-hash');

        $this->assertSame('label.site_maintenance_hash', $config->getLabelTranslationKey());
    }

    public function testSetValueCoercesBooleansToTrueOrFalseStrings(): void
    {
        $config = new Config();

        $config->setValue(true);
        $this->assertSame('true', $config->getValue());

        $config->setValue(false);
        $this->assertSame('false', $config->getValue());
    }

    public function testSetValueFormatsDateTimeAsYmd(): void
    {
        $config = (new Config())->setValue(new \DateTime('2026-07-12'));

        $this->assertSame('2026-07-12', $config->getValue());
    }

    public function testSetValueCastsScalarsToString(): void
    {
        $config = (new Config())->setValue(42);

        $this->assertSame('42', $config->getValue());
    }

    public function testSetValueKeepsNullAsNull(): void
    {
        $config = (new Config())->setValue(null);

        $this->assertNull($config->getValue());
    }

    public function testValidateJsonValueAddsViolationWhenKindIsJsonAndValueIsInvalid(): void
    {
        $config = (new Config())->setKind(Config::TYPE_JSON)->setValue('not-json');

        $violationBuilder = $this->createMock(ConstraintViolationBuilderInterface::class);
        $violationBuilder->expects($this->once())->method('atPath')->with('value')->willReturnSelf();
        $violationBuilder->expects($this->once())->method('addViolation');

        $context = $this->createMock(ExecutionContextInterface::class);
        $context->expects($this->once())
            ->method('buildViolation')
            ->with('label.invalid_json')
            ->willReturn($violationBuilder);

        $config->validateJsonValue($context);
    }

    public function testValidateJsonValueAddsNoViolationWhenValueIsValidJson(): void
    {
        $config = (new Config())->setKind(Config::TYPE_JSON)->setValue('["a","b"]');

        $context = $this->createMock(ExecutionContextInterface::class);
        $context->expects($this->never())->method('buildViolation');

        $config->validateJsonValue($context);
    }

    public function testValidateJsonValueAddsNoViolationWhenKindIsNotJson(): void
    {
        $config = (new Config())->setKind(Config::TYPE_TEXT)->setValue('not-json');

        $context = $this->createMock(ExecutionContextInterface::class);
        $context->expects($this->never())->method('buildViolation');

        $config->validateJsonValue($context);
    }

    public function testValidateJsonValueAddsNoViolationWhenValueIsNullOrEmpty(): void
    {
        $config = (new Config())->setKind(Config::TYPE_JSON)->setValue(null);

        $context = $this->createMock(ExecutionContextInterface::class);
        $context->expects($this->never())->method('buildViolation');

        $config->validateJsonValue($context);
    }

    public function testValidateThemeColorValueAddsViolationForAnInvalidColor(): void
    {
        $config = (new Config())->setGroup(Config::GROUP_THEME)->setSlug('theme-color-primary')->setValue('red; background: url(evil.css)');

        $violationBuilder = $this->createMock(ConstraintViolationBuilderInterface::class);
        $violationBuilder->expects($this->once())->method('atPath')->with('value')->willReturnSelf();
        $violationBuilder->expects($this->once())->method('addViolation');

        $context = $this->createMock(ExecutionContextInterface::class);
        $context->expects($this->once())
            ->method('buildViolation')
            ->with('label.invalid_theme_color')
            ->willReturn($violationBuilder);

        $config->validateThemeColorValue($context);
    }

    public function testValidateThemeColorValueAddsNoViolationForAValidHexColor(): void
    {
        $config = (new Config())->setGroup(Config::GROUP_THEME)->setSlug('theme-color-primary')->setValue('#b30000');

        $context = $this->createMock(ExecutionContextInterface::class);
        $context->expects($this->never())->method('buildViolation');

        $config->validateThemeColorValue($context);
    }

    public function testValidateThemeColorValueAddsNoViolationForAValidRgbaColor(): void
    {
        $config = (new Config())->setGroup(Config::GROUP_THEME)->setSlug('theme-color-secondary')->setValue('rgba(11, 55, 178, .5)');

        $context = $this->createMock(ExecutionContextInterface::class);
        $context->expects($this->never())->method('buildViolation');

        $config->validateThemeColorValue($context);
    }

    public function testValidateThemeColorValueAddsNoViolationForAValidNamedColor(): void
    {
        $config = (new Config())->setGroup(Config::GROUP_THEME)->setSlug('theme-color-background')->setValue('tomato');

        $context = $this->createMock(ExecutionContextInterface::class);
        $context->expects($this->never())->method('buildViolation');

        $config->validateThemeColorValue($context);
    }

    public function testValidateThemeColorValueAddsNoViolationWhenGroupIsNotTheme(): void
    {
        $config = (new Config())->setGroup(Config::GROUP_GENERAL)->setSlug('theme-color-primary')->setValue('red; background: url(evil.css)');

        $context = $this->createMock(ExecutionContextInterface::class);
        $context->expects($this->never())->method('buildViolation');

        $config->validateThemeColorValue($context);
    }

    // theme-mode holds a fixed light/dark/auto choice, not a CSS color, so it's exempt even within the theme group
    public function testValidateThemeColorValueAddsNoViolationWhenSlugIsNotAThemeColor(): void
    {
        $config = (new Config())->setGroup(Config::GROUP_THEME)->setSlug('theme-mode')->setValue('not-a-color-or-a-mode;');

        $context = $this->createMock(ExecutionContextInterface::class);
        $context->expects($this->never())->method('buildViolation');

        $config->validateThemeColorValue($context);
    }

    public function testValidateThemeColorValueAddsNoViolationWhenValueIsNullOrEmpty(): void
    {
        $config = (new Config())->setGroup(Config::GROUP_THEME)->setSlug('theme-color-primary')->setValue(null);

        $context = $this->createMock(ExecutionContextInterface::class);
        $context->expects($this->never())->method('buildViolation');

        $config->validateThemeColorValue($context);
    }
}
