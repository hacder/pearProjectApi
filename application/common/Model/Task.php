<?php

namespace app\common\Model;

use function GuzzleHttp\Promise\task;
use service\DateService;
use think\Db;
use think\facade\Hook;

/**
 * 任务
 * Class Organization
 * @package app\common\Model
 */
class Task extends CommonModel
{
    protected $append = ['priText', 'liked', 'stared', 'childCount', 'hasComment', 'hasSource'];

    public function read($code)
    {
        if (!$code) {
            throw new \Exception('请选择任务', 1);
        }
        $task = self::where(['code' => $code])->field('id', true)->find();
        if (!$task) {
            throw new \Exception('该任务已失效', 404);
        }
        $project = Project::where(['code' => $task['project_code']])->field('name')->find();
        $stage = TaskStages::where(['code' => $task['stage_code']])->field('name')->find();
        $task['executor'] = null;
        if ($task['assign_to']) {
            $task['executor'] = Member::where(['code' => $task['assign_to']])->field('name,code,avatar')->find();
        }
        if ($task['pcode']) {
            $task['parentTask'] = self::where(['code' => $task['pcode']])->field('id', true)->find();
        }
        $task['projectName'] = $project['name'];
        $task['stageName'] = $stage['name'];
        //TODO 查看权限
        return $task;
    }

    /**
     * @param $projectCode
     * @param $deleted
     * @throws \think\exception\DbException
     */
    public function listForProject($projectCode, $deleted)
    {
        $this->_list($where);
    }

    public function dateTotalForProject($projectCode, $beginTime = '', $endTime = '')
    {
        !$beginTime && $beginTime = date("Y-m-d", strtotime("-20 day"));
        !$endTime && $endTime = nowTime();
        $dateList = DateService::getDateFromRange($beginTime, $endTime);
        $list = [];
        if ($dateList) {
            foreach ($dateList as $date) {
                $currentDate = "{$date} 00:00:00";
                $currentDateEnd = "{$date} 23:59:59";
                $total = Task::where("project_code  = '{$projectCode}' and (create_time between '{$currentDate}' and '{$currentDateEnd}')")->count('id');
                $list[] = ['date' => $date, 'total' => $total];
            }
        }
        return $list;
    }

    public function edit($code, $data)
    {
        if (!$code) {
            throw new \Exception('请选择任务', 1);
        }
        $task = self::where(['code' => $code, 'deleted' => 0])->field('id', true)->find();
        if (!$task) {
            throw new \Exception('该任务在回收站中无法编辑', 1);
        }
        if (isset($data['description']) && $data['description'] == '<p><br></p>') {
            $data['description'] = "";
        }
        $result = self::update($data, ['code' => $code]);
        $member = getCurrentMember();
        $type = 'name';
        if (isset($data['name'])) {
            $type = 'name';
        }
        if (isset($data['description'])) {
            $type = 'content';
            if (!$data['description']) {
                $type = 'clearContent';
            }
        }
        if (isset($data['pri'])) {
            $type = 'pri';
        }
        if (isset($data['end_time'])) {
            $type = 'setEndTime';
            if (!$data['end_time']) {
                $type = 'clearEndTime';
            }
        }
        self::taskHook($member['code'], $code, $type);
        //TODO 任务动态
        return $result;
    }

    public function taskSources($code)
    {
        if (!$code) {
            throw new \Exception('请选择任务', 1);
        }
        $task = self::where(['code' => $code])->field('id', true)->find();
        if (!$task) {
            throw new \Exception('该任务不存在', 2);
        }
        $sources = SourceLink::where(['link_code' => $code, 'link_type' => 'task'])->field('id', true)->order('id desc')->select()->toArray();
        if ($sources) {
            foreach ($sources as &$source) {
                $source = SourceLink::getSourceDetail($source['code']);
            }
        }
        return $sources;
    }

    /**
     * @param $code
     * @param bool $like
     * @return bool
     * @throws \think\Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function like($code, $like = true)
    {
        if (!$code) {
            throw new \Exception('请选择任务', 1);
        }
        $task = self::where(['code' => $code, 'deleted' => 0])->field('id', true)->find();
        if (!$task) {
            throw new \Exception('该任务在回收站中不能点赞', 1);
        }
        if ($like) {
            $result = self::where(['code' => $code])->setInc('like');
        } else {
            $result = self::where(['code' => $code])->setDec('like');;
        }
        $member = getCurrentMember();
        TaskLike::likeTask($code, $member['code'], $like);
        return $result;
    }

    /**
     * @param $code
     * @param bool $star
     * @return bool
     * @throws \think\Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function star($code, $star = true)
    {
        if (!$code) {
            throw new \Exception('请选择任务', 1);
        }
        $task = self::where(['code' => $code, 'deleted' => 0])->field('id', true)->find();
        if (!$task) {
            throw new \Exception('该任务在回收站中不能收藏', 1);
        }
        if ($star) {
            $result = self::where(['code' => $code])->setInc('star');
        } else {
            $result = self::where(['code' => $code])->setDec('star');;
        }
        $member = getCurrentMember();
        Collection::starTask($code, $member['code'], $star);
        return $result;
    }

    /**
     * 创建任务
     * @param $stageCode
     * @param $projectCode
     * @param $name
     * @param $memberCode
     * @param string $assignTo
     * @param string $parentCode
     * @return Task
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function createTask($stageCode, $projectCode, $name, $memberCode, $assignTo = '', $parentCode = '')
    {
        if (!$name) {
            throw new \Exception('请填写任务标题', 1);
        }
        $stage = TaskStages::where(['code' => $stageCode])->field('id')->find();
        if (!$stage) {
            throw new \Exception('该任务列表无效', 2);
        }
        $project = Project::where(['code' => $projectCode, 'deleted' => 0])->field('id')->find();
        if (!$project) {
            throw new \Exception('该任务已失效', 3);
        }
        if ($parentCode) {
            $parentTask = self::where(['code' => $parentCode])->find();
            if (!$parentTask) {
                throw new \Exception('父任务无效', 5);
            }
            if ($parentTask['deleted']) {
                throw new \Exception('父任务在回收站中无法编辑', 6);
            }
        }
        if ($assignTo) {
            $assignMember = Member::where(['code' => $assignTo])->field('id')->find();
            if (!$assignMember) {
                throw new \Exception('任务执行人有误', 4);
            }
        }

        Db::startTrans();
        try {
            $taskTitles = explode("\n", $name);
            foreach ($taskTitles as $taskTitle) {
                if (!trim($taskTitle)) {
                    continue;
                }
                $maxNum = self::where(['project_code' => $projectCode])->max('id_num');
                if (!$maxNum) {
                    $maxNum = 0;
                }
                $data = [
                    'create_time' => nowTime(),
                    'code' => createUniqueCode('task'),
                    'create_by' => $memberCode,
                    'assign_to' => $assignTo,
                    'id_num' => $maxNum + 1,
                    'project_code' => $projectCode,
                    'pcode' => $parentCode,
                    'stage_code' => $stageCode,
                    'name' => trim($taskTitle),
                ];
                $result = self::create($data);
//                self::update(['sort' => $result['id']], ['id' => $result['id']]);
                self::taskHook($memberCode, $data['code'], 'create');
                if ($parentCode) {
                    self::taskHook($memberCode, $parentCode, 'createChild', '', '', 0, '', '', ['taskName' => trim($taskTitle)]);
                }
                $isExecutor = 0;
                $logType = 'inviteMember';
                if ($assignTo) {
                    if ($memberCode == $assignTo) {
                        $isExecutor = 1;
                        $logType = 'claim';
                    }
//                    Task::taskHook($memberCode, $data['code'], $logType, $assignTo);
                    TaskMember::inviteMember($assignTo, $data['code'], 1, $isExecutor);
                }
                if (!$assignTo || !$isExecutor) {
                    TaskMember::inviteMember($memberCode, $data['code'], 0, 1);
                }
            }
            //todo 添加任务动态

            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            throw new \Exception($e->getMessage());
        }
        return $result;
    }

    public function taskDone($taskCode, $done)
    {
        if (!$taskCode) {
            throw new \Exception('请选择任务', 1);
        }
        $task = self::where(['code' => $taskCode])->find();
        if (!$task) {
            throw new \Exception('任务已失效', 2);
        }
        if ($task['deleted']) {
            throw new \Exception('任务在回收站中无法进行编辑', 3);
        }
        Db::startTrans();
        try {
            $result = self::update(['done' => $done], ['code' => $taskCode]);
            //todo 添加任务动态，编辑权限检测
            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            throw new \Exception($e->getMessage());
        }
        $member = getCurrentMember();
        $done ? $type = 'done' : $type = 'redo';
        self::taskHook($member['code'], $taskCode, $type);
        if ($task['pcode']) {
            $done ? $type = 'doneChild' : $type = 'redoChild';
            self::taskHook($member['code'], $task['pcode'], $type);
        }
        return $result;
    }

    /**
     * 指派任务
     * @param $taskCode
     * @param $executorCode
     * @return TaskMember|bool
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function assignTask($taskCode, $executorCode)
    {
        if (!$taskCode) {
            throw new \Exception('请选择任务', 1);
        }
        $task = self::where(['code' => $taskCode])->find();
        if (!$task) {
            throw new \Exception('任务已失效', 2);
        }
        if ($task['deleted']) {
            throw new \Exception('任务在回收站中无法进行指派', 3);
        }
        Db::startTrans();
        try {
            $result = TaskMember::inviteMember($executorCode, $taskCode, 1);
            //todo 添加任务动态，编辑权限检测
            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            throw new \Exception($e->getMessage());
        }
        return $result;
    }

    /**
     * @param $taskCode
     * @param $comment
     * @return ProjectLog
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function createComment($taskCode, $comment)
    {
        if (!$taskCode) {
            throw new \Exception('请选择任务', 1);
        }
        $task = self::where(['code' => $taskCode])->find();
        if (!$task) {
            throw new \Exception('任务已失效', 2);
        }
        $data = [
            'member_code' => getCurrentMember()['code'],
            'task_code' => $taskCode,
            'code' => createUniqueCode('taskLog'),
            'create_time' => nowTime(),
            'is_comment' => 1,
            'content' => $comment,
            'type' => 'comment'
        ];
        return ProjectLog::create($data);
    }

    /**
     * 任务排序
     * @param $stageCode string 移到的任务列表code
     * @param $codes array 经过排序的任务code列表
     * @return bool
     */
    public function sort($stageCode, $codes)
    {
        if (!$codes) {
            return false;
        }
        if ($codes) {
            foreach ($codes as $key => $code) {
                self::update(['sort' => $key, 'stage_code' => $stageCode], ['code' => $code]);
            }
            return true;
        }
        return false;
    }

    public function getMemberTasks($memberCode = '', $done = 0, $page = 1, $pageSize = 10)
    {
        if (!$memberCode) {
            $memberCode = getCurrentMember()['code'];
        }
        if ($page < 1) {
            $page = 1;
        }
        $offset = ($page - 1) * $page;
        $limit = $pageSize;
        $prefix = config('database.prefix');
        $sql = "select *,t.id as id,t.name as name,t.code as code from {$prefix}task as t join {$prefix}project as p on t.project_code = p.code where t.done = {$done} and t.deleted = 0 and t.assign_to = '{$memberCode}' and p.deleted = 0 order by t.id desc";
        $total = Db::query($sql);
        $total = count($total);
        $sql .= " limit {$offset},{$limit}";
        $list = Db::query($sql);
        return ['list' => $list, 'total' => $total];
    }

    /**
     * 批量放入回收站
     * @param $stageCode
     * @return Task
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function recycleBatch($stageCode)
    {
        $stage = TaskStages::where(['code' => $stageCode])->find();
        if (!$stage) {
            throw new \Exception('任务列表不存在', 1);
        }
        $where = ['stage_code' => $stageCode, 'deleted' => 0];
        $taskCodes = self::where($where)->column('code');
        $memberCode = getCurrentMember()['code'];
        if ($taskCodes) {
            foreach ($taskCodes as $taskCode) {
                self::taskHook($memberCode, $taskCode, 'recycle');
            }
        }
        $result = self::update(['deleted' => 1, 'deleted_time' => nowTime()], $where);
        return $result;
    }

    /**
     * 放入回收站
     * @param $code
     * @return Project
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function recycle($code)
    {
        $info = self::where(['code' => $code])->find();
        if (!$info) {
            throw new \Exception('任务不存在', 1);
        }
        if ($info['deleted']) {
            throw new \Exception('任务已在回收站', 2);
        }
        $result = self::update(['deleted' => 1, 'deleted_time' => nowTime()], ['code' => $code]);
        self::taskHook(getCurrentMember()['code'], $code, 'recycle');
        return $result;
    }

    /**
     * 恢复任务
     * @param $code
     * @return Project
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function recovery($code)
    {
        $info = self::where(['code' => $code])->find();
        if (!$info) {
            throw new \Exception('任务不存在', 1);
        }
        if (!$info['deleted']) {
            throw new \Exception('任务已恢复', 2);
        }
        $result = self::update(['deleted' => 0], ['code' => $code]);
        self::taskHook(getCurrentMember()['code'], $code, 'recovery');
        return $result;
    }

    public function del($code)
    {
        //权限判断
        $info = self::where(['code' => $code])->find();
        if (!$info) {
            throw new \Exception('任务不存在', 1);
        }
        Db::startTrans();
        try {
            self::where(['code' => $code])->delete();
            self::where(['pcode' => $code])->delete();
            TaskMember::where(['task_code' => $code])->delete();
            TaskLike::where(['task_code' => $code])->delete();
            ProjectLog::where(['source_code' => $code, 'action_type' => 'task'])->delete();
            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            throw new \Exception($e->getMessage());
        }
        return true;
    }


    public function getPriTextAttr($value, $data)
    {
        if (!isset($data['pri'])) {
            $data['pri'] = 0;
        }
        $status = [0 => '普通', 1 => '紧急', 2 => '非常紧急'];
        return $status[$data['pri']];
    }

    public function getChildCountAttr($value, $data)
    {
        $childTasks = [];
        if (isset($data['code'])) {
            $childTaskCount = self::where(['pcode' => $data['code'], 'deleted' => 0])->count('id');
            $childTasks[] = $childTaskCount;
            $childTaskCount = self::where(['pcode' => $data['code'], 'deleted' => 0, 'done' => 1])->count('id');
            $childTasks[] = $childTaskCount;
        }
        return $childTasks;
    }

    public function getHasCommentAttr($value, $data)
    {
        $comment = 0;
        if (isset($data['code'])) {
            $comment = ProjectLog::where(['source_code' => $data['code'], 'type' => 'task', 'is_comment' => 1])->count('id');
        }
        return $comment;
    }

    public function getHasSourceAttr($value, $data)
    {
        $sources = 0;
        if (isset($data['code'])) {
            $sources = SourceLink::where(['link_code' => $data['code'], 'link_type' => 'task'])->count('id');
        }
        return $sources;
    }

    public function getLikedAttr($value, $data)
    {
        $like = 0;
        if (isset($data['code'])) {
            $member = getCurrentMember();
            $taskLike = TaskLike::where(['task_code' => $data['code'], 'member_code' => $member['code']])->find();
            if ($taskLike) {
                $like = 1;
            }
        }
        return $like;
    }

    public function getStaredAttr($value, $data)
    {
        $stared = 0;
        if (isset($data['code'])) {
            $member = getCurrentMember();
            $taskStar = Collection::where(['source_code' => $data['code'], 'type' => 'task', 'member_code' => $member['code']])->find();
            if ($taskStar) {
                $stared = 1;
            }
        }
        return $stared;
    }

    /** 任务变动钩子
     * @param $memberCode
     * @param $taskCode
     * @param string $type
     * @param string $toMemberCode
     * @param int $isComment
     * @param string $remark
     * @param string $content
     * @param string $fileCode
     * @param array $data
     * @param string $tag
     */
    public static function taskHook($memberCode, $taskCode, $type = 'create', $toMemberCode = '', $isComment = 0, $remark = '', $content = '', $fileCode = '', $data = [], $tag = 'task')
    {
        $data = ['memberCode' => $memberCode, 'taskCode' => $taskCode, 'remark' => $remark, 'type' => $type, 'content' => $content, 'isComment' => $isComment, 'toMemberCode' => $toMemberCode, 'fileCode' => $fileCode, 'data' => $data, 'tag' => $tag];
        Hook::listen($tag, $data);

    }
}
