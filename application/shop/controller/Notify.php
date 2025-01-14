<?php

namespace app\shop\controller;

use app\common\controller\Fun;
use app\common\controller\Hm;
use think\Controller;
use think\Db;
use think\Cache;

use app\common\controller\Email;
use think\Session;

/**
 * 回调类
 */
class Notify extends Controller {

    public $site = [];

    public $options = [];

    public $timestamp = null;

    public function _initialize() {
        parent::_initialize(); // TODO: Change the autogenerated stub
        $this->site = \think\Config::get('site');
        $options = db::name('options')->select();
        foreach($options as $val) $this->options[$val['option_name']] = $val['option_content'];
        $this->options['buy_data'] = json_decode($this->options['buy_data'], true);
        $this->timestamp = time();
        $active_plugins = Db::name('options')->where(['option_name' => 'active_plugin'])->value('option_content');
        $active_plugins = empty($active_plugins) ? [] : unserialize($active_plugins);
        if ($active_plugins && is_array($active_plugins)) {
            foreach($active_plugins as $plugin) {
                if(true === checkPlugin($plugin) && substr($plugin, -13) != '_template.php' && substr($plugin, -8) != '_pay.php') {
                    include_once(ROOT_PATH . 'public/content/plugin/' . $plugin);
                }
            }
        }
    }

    public function test(){

//         $order = db::name('order')->where(['id' => 29])->find();
//         $goods = db::name('goods')->where(['id' => $order['goods_id']])->find();
    }


    /**
     * 充值回调通知
     */
    public function recharge_notify(){

        $timestamp = time(); //时间戳
        $receive_type = $this->request->param('receive_type'); //接收回调报文的方式
        $out_trade_no = $this->request->param('out_trade_no'); //订单号
        $notice_type = $this->request->param('notice_type'); //通知方式 同步或异步
        switch($receive_type){
            case 'input':
                $content = file_get_contents("php://input");
                break;
            case 'post':
                $content = $this->request->post();
                break;
            case 'get':
                $content = $this->request->get();
                break;
        }

        if(cache::has($out_trade_no)){
            sleep(1);
            if($notice_type == 'notify'){
                echo 'success'; die;
            }else{
                header("location: /user.html"); die;
            }
        }

        cache::set($out_trade_no, true);

        $recharge = db::name('recharge')->where(['out_trade_no' => $out_trade_no])->find();

        if(!empty($recharge['pay_time'])){ //重复通知
            if($notice_type == 'notify'){
                echo 'success'; die;
            }else{
                header("location: /user.html"); die;
            }

        }

        $pluginPath = ROOT_PATH . 'public/content/plugin/' . $recharge['pay_plugin'] . '/' . $recharge['pay_plugin'] . '.php';
        require_once $pluginPath;

        $check_sign = checkSign($content);

        if($check_sign){ //验签成功

            $update = [
                'pay_time' => $timestamp, //支付时间
            ];

            db::name('recharge')->where(['out_trade_no' => $out_trade_no])->update($update);
            db::name('user')->where(['id' => $recharge['user_id']])->setInc('money', $recharge['money']);

            cache::rm($out_trade_no);


            if($notice_type == 'notify'){
                echo 'success'; die;
            }else{
                header("location: /user.html"); die;
            }

        }else{
            cache::rm($out_trade_no);
            if($notice_type == 'notify'){
                echo 'fail'; die;
            }else{
                echo '验签失败'; die;
            }
        }

    }



    /**
     * 回调通知
     */
    public function index(){
        Db::startTrans();
        try{

            $timestamp = time(); //时间戳
            $pay_plugin = $this->request->param('pay_plugin');
            $pluginPath = ROOT_PATH . 'public/content/plugin/' . $pay_plugin . '_pay/' . $pay_plugin . '_pay.php';
            require_once $pluginPath;
            $check_sign = checkSign();

            if($check_sign){ //验签成功
                $order = db::name('order')->where(['order_no' => $check_sign])->lock(true)->find();
                if(!$order){
                    Db::rollback();
                    die('fail');
                }
                if($order['status'] != 'wait-pay'){ //重复通知
                    Db::rollback();
                    echo 'success'; die;
                }

                $goods = db::name('goods')->where(['id' => $order['goods_id']])->find();
                $update = [
                    'status' => 'wait-send', // 通知后改为代发货
                    'pay_time' => $timestamp, //支付时间
                ];
                $order['pay_time'] = $timestamp;
                db::name('order')->where(['id' => $order['id']])->update($update);
                $result = Hm::handleOrder($goods, $order, $this->options);
                Db::commit();

                $n_order_data = [
                    'goods_name' => $goods['name'],
                    'out_trade_no' => $order['order_no'],
                    'buy_num' => $order['buy_num'],
                    'goods_price' => $order['goods_money'],
                    'order_money' => $order['money'],
                    'cdk' => $result['data']['cdk'],
                    'create_time' => $order['create_time'],
                    'pay_type' => $order['pay_type'],
                    'pay_time' => $order['pay_time'],
                    'details' => $goods['details'],
                    'stock' => $result['data']['stock']
                ];

                $email = [];
                if(!empty($this->options['buy_data'][0]['email']) && !empty($order['email'])) $email[] = $order['email'];
                if(!empty($this->options['buy_data'][1]['email']) && !empty($order['password'])) $email[] = $order['password'];

                if(!empty($this->options['n_order_ad'])){
                    $obj_path = "notice\\" . lcfirst($this->options['n_order_ad']) . "\\{$this->options['n_order_ad']}";
                    $obj = new $obj_path;
                    $obj->nOrderAd($n_order_data);
                }
                if(!empty($this->options['n_order_us']) && !empty($email)){
                    $obj_path = "notice\\" . lcfirst($this->options['n_order_us']) . "\\{$this->options['n_order_us']}";
                    $obj = new $obj_path;
                    foreach($email as $val){
                        $n_order_data['email'] = $val;
                        $obj->nOrderUs($n_order_data);
                    }
                }

                try{
                    doAction('order_notify', $order, $goods);
                }catch(\Exception $e){}


                echo 'success'; die;

            }else{
                Db::rollback();
                echo 'fail'; die;
            }
        } catch (\Exception $e) {
            // 回滚事务
            Db::rollback();
            echo $e->getMessage();
            echo '<br>';
            echo 'fail';die;
        }

    }

    /**
     * 对接回调
     */
    public function dock_callback_order(){
        $param = file_get_contents('php://input');
        $content = json_encode($param);
        db::name('test')->insert(['content' => $content, 'createtime' => $this->timestamp]);
    }

    //记录用户账单
    public function record_user_bill($order, $goods, $timestamp){
        $bill_insert = [
            'uid' => $order['uid'],
            'description' => '购买商品 ' . $goods['name'] . ' x' . $order['buy_num'],
            'createtime' => $timestamp,
            'value' => '-' . sprintf("%.2f", $order['money']),
            'type' => 'goods', //购买商品
        ];
        db::name('money_bill')->insert($bill_insert);
        db::name("user")->where(["id" => $order["uid"]])->setInc("consume", $order["money"]);
    }




}
