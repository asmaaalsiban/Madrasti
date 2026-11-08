<?php

declare(strict_types=1);

namespace App\Shared\Export;

use OpenSpout\Common\Entity\Style\Border;
use OpenSpout\Common\Entity\Style\BorderPart;
use OpenSpout\Common\Entity\Style\CellAlignment;
use OpenSpout\Common\Entity\Style\CellVerticalAlignment;
use OpenSpout\Common\Entity\Style\Style;

/** The look shared by the school's printed forms in Excel: a navy header, tinted cells, thin borders, centred text. */
trait SheetStyles
{
    private const NAVY = '0B2A4A';

    private const TINT = 'EAF1F8';

    private const SAND = 'F7F1E3';

    private function border(): Border
    {
        $part = fn (string $name) => new BorderPart($name, 'CBD5E1', Border::WIDTH_THIN, Border::STYLE_SOLID);

        return new Border($part(Border::TOP), $part(Border::BOTTOM), $part(Border::LEFT), $part(Border::RIGHT));
    }

    private function style(int $size = 11, bool $bold = false, string $color = '111827', ?string $fill = null, bool $bordered = false, bool $wrap = true): Style
    {
        $s = (new Style())->setFontSize($size)->setFontColor($color)->setCellAlignment(CellAlignment::CENTER)->setCellVerticalAlignment(CellVerticalAlignment::CENTER)->setShouldWrapText($wrap);
        if ($bold) {
            $s->setFontBold();
        }
        if ($fill !== null) {
            $s->setBackgroundColor($fill);
        }
        if ($bordered || $fill !== null) {
            $s->setBorder($this->border());
        }

        return $s;
    }
}
