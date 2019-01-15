<?php

namespace app\index\controller;

use app\common\Model\Areas;
use app\common\Model\CommonModel;
use controller\BasicApi;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use service\MessageService;
use think\facade\Cache;
use think\facade\Request;
use think\File;


/**
 * 应用入口控制器
 * @author Vilson
 */
class Index extends BasicApi
{


    /**
     * @throws \PhpOffice\PhpSpreadsheet\Exception
     */
    public function index()
    {
//        Cache::store('redis')->set('vilson',5,5);
//        Cache::store('redis')->dec('vilson',1);
//        $this->success('success', Cache::store('redis')->get('vilson'));

//        $spreadsheet = new Spreadsheet();
//        $sheet = $spreadsheet->getActiveSheet();
//        $sheet->setCellValue('A1', 'Hello World !');
//        $writer = new Xlsx($spreadsheet);
//        $writer->save('hello world.xlsx');

        echo "success";
        die;
        $this->success('success');

    }

    /**
     * 获取行政区划数据
     */
    public function getAreaData()
    {
        $this->success('', Areas::createJsonForAnt());

    }

    /**
     * 将webscoket的client_id和用户id进行绑定
     * @param Request $request
     */
    public function bindClientId(Request $request)
    {
        $clientId = $request::param('client_id');
        $uid = $request::param('uid');
        if (!$uid) {
            $uid = session('user.id');
        }
        $messageService = new MessageService();
        $messageService->bindUid($clientId, $uid);
        $messageService->joinGroup($clientId, 'admin');
        $this->success('', $uid);
    }

    public function createNotice(Request $request)
    {
        $data = $request::post();
        $notifyModel = new \app\common\Model\Notify();
        $result = $notifyModel->add($data['title'], $data['content'], $data['type'], $data['from'], $data['to'], $data['action'], $data['send_data'], $data['terminal']);
        $messageService = new MessageService();
        $messageService->sendToUid($data['to'], $data, $data['action']);
        $this->success('', $result);
    }

    public function pushNotice(Request $request)
    {
        $uid = $request::param('uid');
        $messageService = new MessageService();
        $messageService->sendToUid($uid, '888', 'notice');
        $this->success();

    }

    public function pushNoticeGroup(Request $request)
    {
        $group = $request::param('group');
        $messageService = new MessageService();
        $messageService->sendToGroup($group, '999', 'noticeGroup');
//        $this->success('群组消息', $group);
    }
}
