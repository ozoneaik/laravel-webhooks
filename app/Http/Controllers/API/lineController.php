<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\ActiveConversations;
use App\Models\ChatHistory;
use App\Models\Customers;
use App\Models\PlatformAccessTokens;
use App\Models\Rates;
use App\Services\LineService;
use App\Services\PusherService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class lineController extends Controller
{
    protected LineService $lineService;
    protected PusherService $pusherService;

    public function __construct(LineService $lineService, PusherService $pusherService)
    {
        $this->lineService = $lineService;
        $this->pusherService = $pusherService;
    }

    public function lineWebHook(Request $request): JsonResponse
    {
        Log::channel('testDebug')->info('testDebug');
        DB::beginTransaction();
        Log::info($request->all());
        $checkSendMenu = false;
        $status = 400;
        try {
            $TOKEN = '';
            /* เตรียมข้อมูล */
            if (count($request['events']) <= 0) throw new \Exception('event not data');
            if ($request['events'][0]['type'] !== 'message') throw new \Exception('event type is not message');
            $events = $request['events'][0];
            $custId = $events['source']['userId'];
            $customer = '';
            /* ---------------------------------------------------------------------------------------------------- */
            /* ดึง profile ลูกค้า และสร้างข้อมูลลูกค้า หากยังไม่มีในฐานข้อมูล */
            $URL = "https://api.line.me/v2/bot/profile/$custId";
            $channelAccessTokens = PlatformAccessTokens::all();
            $find = false;
            foreach ($channelAccessTokens as $token) {
                $response = Http::withHeaders([
                    'Authorization' => "Bearer " . $token['accessToken'],
                ])->get($URL);
                if ($response->status() === 200) {
                    $TOKEN = $token['accessToken'];
                    $find = true;
                    Log::info("พบ" . $response->status());
                    $checkCustomer = Customers::where('custId', $custId)->first();
                    if (!$checkCustomer) {
                        $res = $response->json();
                        $createCustomer = new Customers();
                        $createCustomer['custId'] = $custId;
                        $createCustomer['custName'] = $res['displayName'];
                        $createCustomer['avatar'] = $res['pictureUrl'];
                        $createCustomer['description'] = "ทักมาจากไลน์ " . $token['description'];
                        $createCustomer['platformRef'] = $token['id'];
                        $createCustomer->save();
                        $customer = $createCustomer;
                    } else  $customer = $checkCustomer;
                    break;
                } else Log::info("ไม่พบ" . $response->status());
            }
            if (!$find) throw new \Exception('ไม่เจอ access token ที่เข้ากันได้');
            /* ---------------------------------------------------------------------------------------------------- */
            /* ตรวจสอบว่า custId คนนี้มี rate ที่สถานะเป็น pending หรือ progress หรือไม่ ถ้าไม่ */
            $checkRates = Rates::where('custId', $custId)->where('status', '!=', 'success')->first();
            // ถ้าไม่เจอ Rates ที่ status เป็น pending หรือ progress ให้สร้าง Rates กับ activeConversations ใหม่
            if (!$checkRates) {
                $rate = new Rates();
                $rate['custId'] = $custId;
                $rate['rate'] = 0;
                $rate['status'] = 'progress';
                $rate['latestRoomId'] = 'ROOM00';
                $rate->save();
                $activeConversation = new ActiveConversations();
                $activeConversation['custId'] = $custId;
                $activeConversation['roomId'] = 'ROOM00';
                $activeConversation['empCode'] = 'BOT';
                $activeConversation['receiveAt'] = Carbon::now();
                $activeConversation['rateRef'] = $rate['id'];
                $activeConversation->save();
                $conversationRef = $activeConversation['id'];

                // ส่งเมนูตัวเลือกให้ลูกค้าเลือก
                $sendMenu = $this->lineService->sendMenu($custId, $TOKEN,$activeConversation['id']);
                if (!$sendMenu['status']) throw new \Exception($sendMenu['message']);
                else $checkSendMenu = true;

            } else {
                $rateRef = $checkRates['id'];
                $latestRoomId = $checkRates['latestRoomId'];
                $checkActiveConversation = ActiveConversations::where('rateRef', $rateRef)
                    ->where('roomId', $latestRoomId)
                    ->where('endTime', null)
                    ->first();
                if ($checkActiveConversation) {
                    $conversationRef = $checkActiveConversation['id'];
                    // ถ้าเช็คแล้วว่า มีการรับเรื่อง (receiveAt) แล้วยังไม่มี startTime ให้ startTime = carbon::now()
                    if (!empty($checkActiveConversation['receiveAt'])) {
                        if (empty($checkActiveConversation['startTime'])) {
                            $checkActiveConversation['startTime'] = carbon::now();
                        }
                        if ($checkActiveConversation->save()) {
                            $status = 200;
                        } else throw new \Exception('เจอปัญหา startTime ไม่ได้');
                    }
                } else throw new \Exception('ไม่พบ conversationRef จากตาราง ActiveConversations');
            }
            /* ---------------------------------------------------------------------------------------------------- */
            /* สร้าง chatHistory */
            $messages['contentType'] = $events['message']['type'];
            switch ($events['message']['type']) {
                case 'text':
                    $messages['content'] = $events['message']['text'];
                    break;
                case 'image':
                    $imageId = $events['message']['id'];
                    $messages['content'] = $this->lineService->handleMedia($imageId, $TOKEN);
                    break;
                case 'sticker':
                    $stickerId = $events['message']['stickerId'];
                    $pathStart = 'https://stickershop.line-scdn.net/stickershop/v1/sticker/';
                    $pathEnd = '/iPhone/sticker.png';
                    $newPath = $pathStart . $stickerId . $pathEnd;
                    $messages['content'] = $newPath;
                    break;
                    case 'video':
                        $videoId = $events['message']['id'];
                        $messages['content'] = $this->lineService->handleMedia($videoId, $TOKEN);
                        break;

                default:
                    $messages['content'] = 'ไม่สามารถตรวจสอบได้ว่าลูกค้าส่งอะไรเข้ามา';
            }
            $chatHistory = new ChatHistory();
            $chatHistory['custId'] = $custId;
            $chatHistory['content'] = $messages['content'];
            $chatHistory['contentType'] = $messages['contentType'];
            $chatHistory['sender'] = json_encode($customer);
            $chatHistory['conversationRef'] = $conversationRef;
            $chatHistory->save();

            // กรองการส่งต่อถ้า rate ยังอยู่ในห้อง Bot
            $R = $rate ?? $checkRates;
            if ($R['latestRoomId'] === 'ROOM00') {
                if (!$checkSendMenu) {
                    $change = $this->lineService->handleChangeRoom($chatHistory['content'], $R, $TOKEN,$conversationRef);
                        $change['status'] ?? throw new \Exception($change['message']);
                }
            }


            /* ---------------------------------------------------------------------------------------------------- */
            $message = 'มีข้อความใหม่เข้ามา';
            $detail = 'ไม่มีข้อผิดพลาด';
            $notification = $this->pusherService->newMessage($chatHistory, false, 'มีข้อความใหม่เข้ามา');
            if (!$notification['status']) {
                throw new \Exception('การแจ้งเตือนผิดพลาด');
            }
            $status = 200;
            DB::commit();
        } catch (\Exception $e) {
            if ($e->getMessage() === 'event not data') {
                Log::info('test not event');
                $message = $e->getMessage();
                $detail = 'ไม่มีข้อผิดพลาด';
                $status = 200;
            } else {
                $message = 'เกิดข้อผิดพลาดในการรับข้อความ';
                $detail = $e->getMessage();
            }
            Log::info($e->getMessage());
            DB::rollBack();
        } finally {
            return response()->json([
                'message' => $message,
                'detail' => $detail,
            ], $status);
        }
    }
}
