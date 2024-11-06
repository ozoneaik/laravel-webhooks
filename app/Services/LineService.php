<?php

namespace App\Services;

use App\Models\ActiveConversations;
use App\Models\botMenu;
use App\Models\ChatHistory;
use App\Models\ChatRooms;
use App\Models\Customers;
use App\Models\Rates;
use App\Models\User;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class LineService
{
    public function handleMedia($mediaId, $token): string
    {
        if (!$mediaId) {
            return 'No image ID provided';
        }

        $url = "https://api-data.line.me/v2/bot/message/$mediaId/content";
        $client = new Client();
        $response = $client->request('GET', $url, [
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ]);

        $imageContent = $response->getBody()->getContents();
        $contentType = $response->getHeader('Content-Type')[0];
        $extension = match ($contentType) {
            'image/jpeg' => '.jpg',
            'image/png' => '.png',
            'image/gif' => '.gif',
            'video/mp4' => '.mp4',
            'video/webm' => '.webm',
            'video/ogg' => '.ogg',
            'video/avi' => '.avi',
            'video/mov' => '.mov',
            default => '.bin',
        };

        $imagePath = 'line-images/' . $mediaId . $extension;
        Storage::disk('public')->put($imagePath, $imageContent);
        $fullPath = env('IMAGE_URL').$imagePath;
        return $fullPath;
//        return asset('storage/' . $imagePath);
    }

    public function sendMenu($custId, $token,$activeConversationId): array
    {
        try {
            $customer = Customers::where('custId', $custId)->first();
            $botMenus = botMenu::select('bot_menus.menuName')
                ->join('platform_access_tokens', 'bot_menus.botTokenId', '=', 'platform_access_tokens.id')
                ->join('customers', 'platform_access_tokens.id', '=', 'customers.platformRef')
                ->where('customers.custId', $custId)
                ->get();
            $actions = [];
            if (count($botMenus) > 0) {
                foreach ($botMenus as $botMenu) {
                    $actions[] = [
                        'type' => 'message',
                        'text' => $botMenu->menuName,
                        'label' => $botMenu->menuName,
                    ];
                }
            }else{
                $actions[] = [
                    'type' => 'message',
                    'text' => 'อื่นๆ',
                    'label' => 'อื่นๆ'
                ];
            }
            $body = [
                "to" => $custId,
                'messages' => [
                    [
                        'type' => 'text',
                        'text' => "สวัสดีคุณ ".$customer['custName']." เพื่อให้การบริการที่รวดเร็ว กรุณาเลือกหัวด้านล่างเพื่อส่งต่อให้เจ้าหน้าที่เพื่อมาบริการท่านต่อไป  ขอบคุณครับ/ค่ะ",
                    ],
                    [
                        'type' => 'template',
                        'altText' => 'this is a buttons template',
                        'template' => [
                            'type' => 'buttons',
                            'title' => 'ยินดีต้อนรับ! 🙏',
                            'text' => 'กรุณาเลือกเมนูที่ท่านต้องการสอบถาม',
                            'actions' => $actions
                        ]
                    ]
                ]
            ];
            $res = $this->linePushMessage($token, $body);
            if ($res['status']) {
                $chat = new ChatHistory();
                $chat['custId'] = $custId;
                $chat['content'] = "สวัสดีคุณ ".$customer['custName']." เพื่อให้การบริการที่รวดเร็ว กรุณาเลือกหัวด้านล่างเพื่อส่งต่อให้เจ้าหน้าที่เพื่อมาบริการท่านต่อไป  ขอบคุณครับ/ค่ะ";
                $chat['contentType'] = 'text';
                $chat['sender'] = json_encode([
                    'empCode' => 'BOT',
                    'name' => 'BOT'
                ]);
                $chat['conversationRef'] = $activeConversationId;
                if ($chat->save()){
                    Log::info('saves');
                }else{
                    Log::info('not saves');
                }
                $data['status'] = true;
                $data['message'] = $res['message'];
            } else throw new \Exception($res['message']);
        } catch (\Exception $e) {
            $data['status'] = false;
            $data['message'] = $e->getMessage();
        } finally {
            return $data;
        }
    }

    //BOT เปลี่ยนห้อง
    public function handleChangeRoom($content, $rate, $token,$conversationRef): array
    {
        $detail = "ระบบกำลังส่งต่อให้เจ้าหน้าที่ที่รับผิดชอบเพื่อเร่งดำเนินการเข้ามาสนทนา กรุณารอสักครู่";
        try {
            $custId = $rate['custId'];
            $updateRate = Rates::where('id', $rate['id'])->first();
            DB::beginTransaction();
            $chatRooms = ChatRooms::select('roomId', 'roomName')->get();

            foreach ($chatRooms as $key => $chatRoom) {
                $check = botMenu::where('menuName', $content)->first();
                Log::info("บอทเปลี่ยนห้อง RateId >> $rate->id");
                if ($check) { //$content === $prefix
                    Log::info('อยู่ใน menu');
                    $text = $content;
                    // ทำการ update ห้องในตาราง rate
                    $updateRate->latestRoomId = $check->roomId;
                    $updateRate->status = 'pending';
                    $updateRate->save();
                    // ทำการสร้าง active
                    $AC = new ActiveConversations();
                    $AC['custId'] = $custId;
                    $AC['roomId'] = $check->roomId;
                    $AC['from_empCode'] = 'BOT';
                    $AC['from_roomId'] = 'ROOM00';
                    $AC['rateRef'] = $rate['id'];
                    $AC->save();
                    $body = [
                        "to" => $custId,
                        'messages' => [[
                            'type' => 'text',
                            'text' => "ระบบกำลังส่งต่อให้เจ้าหน้าที่ที่รับผิดชอบเพื่อเร่งดำเนินการเข้ามาสนทนา กรุณารอสักครู่",
                        ]]
                    ];
                    break;
                } else {
                    if ($key === count($chatRooms) - 1) {
                        Log::info('ไม่อยู่ใน menu');
                        $updateRate->latestRoomId = 'ROOM01';
                        $updateRate->status = 'pending';
                        $updateRate->save();
                        // ทำการสร้าง active
                        $AC = new ActiveConversations();
                        $AC['custId'] = $custId;
                        $AC['roomId'] = 'ROOM01';
                        $AC['from_empCode'] = 'BOT';
                        $AC['from_roomId'] = 'ROOM00';
                        $AC['rateRef'] = $rate['id'];
                        $AC->save();
                        $body = [
                            "to" => $custId,
                            'messages' => [[
                                'type' => 'text',
                                'text' => "ระบบกำลังส่งต่อให้เจ้าหน้าที่ที่รับผิดชอบเพื่อเร่งดำเนินการเข้ามาสนทนา กรุณารอสักครู่",
                            ]]
                        ];
                    }
                }
            }


            // สร้าง chatHistory ด้วย
            Log::info('Test');
            $chat = new ChatHistory();
            $chat['custId'] = $custId;
            $chat['content'] = $detail;
            $chat['contentType'] = 'text';
            $chat['sender'] = json_encode([
                'empCode' => 'BOT',
                'name' => 'BOT'
            ]);
            $chat['conversationRef'] = $conversationRef;
            if ($chat->save()){
                Log::info('saves');
            }else{
                Log::info('not saves');
            }

            $res = $this->linePushMessage($token, $body);
            if ($res['status']) {
                $data['status'] = true;
                $data['message'] = $res['message'];
            } else throw new \Exception($res['message']);
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            $data['status'] = true;
            $data['message'] = $e->getMessage();
        } finally {
            return $data;
        }
    }

    private function linePushMessage($token, $body): array
    {
        try {
            $UrlPush = 'https://api.line.me/v2/bot/message/push';
            $res = Http::withHeaders([
                'Authorization' => 'Bearer ' . $token
            ])->asJson()->post($UrlPush, $body);
            if ($res->status() == 200) {
                $data['status'] = true;
                $data['message'] = 'successful';
            } else {
                Log::info($res->json());
                throw new \Exception('not successful');
            }
        } catch (\Exception $e) {
            $data['status'] = false;
            $data['message'] = $e->getMessage();
        } finally {
            return $data;
        }
    }

}
