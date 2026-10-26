<?php

namespace App\Services;

use App\Models\FcmToken;
use App\Models\Pegawai;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\CloudMessage;

class PushService
{
    public function __construct(private Messaging $messaging) {}

    public function sendToUser(Pegawai $pegawai, string $title, string $body, string $url = '/dashboard'): void
    {
        $tokens = $pegawai->fcmTokens()->pluck('token')->all();
        if (empty($tokens)) {
            return;
        }

        // Data-only: service worker yang menampilkan notifikasi dan mengatur tujuan klik.
        $message = CloudMessage::new()
            ->withData([
                'title' => $title,
                'body'  => $body,
                'url'   => $url,
            ])
            ->withWebPushConfig(['headers' => ['Urgency' => 'high']]);

        $report = $this->messaging->sendMulticast($message, $tokens);

        // Hapus token yang sudah tidak valid (browser di-reset / izin dicabut)
        $invalid = array_merge($report->invalidTokens(), $report->unknownTokens());
        if (!empty($invalid)) {
            FcmToken::whereIn('token', $invalid)->delete();
        }
    }
}
