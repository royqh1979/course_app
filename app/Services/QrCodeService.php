<?php

declare(strict_types=1);

namespace App\Services;

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

/**
 * 二维码生成：服务端输出 SVG，无需任何前端库，内网/离线可用。
 */
final class QrCodeService
{
    /** 生成指定内容的二维码 SVG 字符串（去除 XML 声明，便于内嵌 HTML） */
    public function svg(string $content): string
    {
        $options = new QROptions([
            'outputType' => QRCode::OUTPUT_MARKUP_SVG,
            'outputBase64' => false,
            'eccLevel' => QRCode::ECC_M,
        ]);
        $svg = (new QRCode($options))->render($content);
        return (string) preg_replace('/^<\?xml[^?]*\?>\s*/', '', $svg);
    }
}
