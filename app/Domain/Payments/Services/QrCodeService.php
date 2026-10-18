<?php

declare(strict_types=1);

namespace App\Domain\Payments\Services;

use App\Domain\Shared\Errors\ErrorCode;
use App\Domain\Shared\Errors\ValidationException;
use chillerlan\QRCode\Output\QRMarkupSVG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

final class QrCodeService
{
    public function svg(string $payload, int $size = 220): string
    {
        if (! in_array($size, [220, 260], true) || $payload === '' || strlen($payload) > 2000) {
            throw new ValidationException(ErrorCode::VALIDATION_FAILED);
        }
        $svg = (new QRCode(new QROptions([
            'outputInterface' => QRMarkupSVG::class,
            'outputBase64' => false,
            'svgAddXmlHeader' => false,
            'drawCircularModules' => false,
        ])))->render($payload);

        return str_replace('<svg ', '<svg width="'.$size.'" height="'.$size.'" ', $svg);
    }
}
