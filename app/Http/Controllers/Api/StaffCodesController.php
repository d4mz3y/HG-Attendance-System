<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Staff;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;
use Picqer\Barcode\BarcodeGeneratorPNG;
use Symfony\Component\HttpFoundation\Response;

class StaffCodesController extends Controller
{
    public function qr(Staff $staff): Response
    {
        $payload = $staff->staff_id;

        $qrCode = new QrCode(
            data: $payload,
            encoding: new Encoding('ISO-8859-1'),
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: 320,
            margin: 10,
            roundBlockSizeMode: RoundBlockSizeMode::Margin,
        );

        $writer = new PngWriter();
        $result = $writer->write($qrCode);

        return response($result->getString(), 200, [
            'Content-Type' => $result->getMimeType(),
            'Content-Disposition' => 'attachment; filename="'.$this->downloadFilename($staff, 'qr').'"',
        ]);
    }

    public function barcode(Staff $staff): Response
    {
        $generator = new BarcodeGeneratorPNG();
        $png = $generator->getBarcode(
            $staff->staff_id,
            $generator::TYPE_CODE_128,
            2,
            80
        );

        return response($png, 200, [
            'Content-Type' => 'image/png',
            'Content-Disposition' => 'attachment; filename="'.$this->downloadFilename($staff, 'barcode').'"',
        ]);
    }

    protected function downloadFilename(Staff $staff, string $kind): string
    {
        $slug = preg_replace('/[^A-Za-z0-9_-]+/', '_', $staff->staff_id) ?: 'staff';

        return 'HoganGuards_staff_'.$slug.'_'.$kind.'.png';
    }
}
