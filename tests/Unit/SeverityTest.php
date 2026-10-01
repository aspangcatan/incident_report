<?php

namespace Tests\Unit;

use App\Enums\Severity;
use PHPUnit\Framework\TestCase;

class SeverityTest extends TestCase
{
    public function test_there_are_five_levels_in_order(): void
    {
        $this->assertSame(
            ['level_1_low', 'level_2_moderate', 'level_3_high', 'level_4_critical', 'level_5_sentinel'],
            array_map(fn (Severity $s) => $s->value, Severity::cases())
        );
    }

    public function test_labels_numerals_and_meanings(): void
    {
        $this->assertSame('Critical', Severity::Level4Critical->label());
        $this->assertSame('Level V', Severity::Level5Sentinel->romanNumeral());
        $this->assertSame('Temporary harm or intervention required', Severity::Level2Moderate->meaning());
        $this->assertSame('Death or serious permanent harm / other agency-defined sentinel event', Severity::Level5Sentinel->meaning());
    }

    public function test_only_level_five_is_sentinel(): void
    {
        $this->assertTrue(Severity::Level5Sentinel->isSentinel());
        $this->assertFalse(Severity::Level4Critical->isSentinel());
    }

    public function test_high_or_above(): void
    {
        $this->assertFalse(Severity::Level1Low->isHighOrAbove());
        $this->assertFalse(Severity::Level2Moderate->isHighOrAbove());
        $this->assertTrue(Severity::Level3High->isHighOrAbove());
        $this->assertTrue(Severity::Level4Critical->isHighOrAbove());
        $this->assertTrue(Severity::Level5Sentinel->isHighOrAbove());
    }
}
