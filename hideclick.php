<?php
namespace Filters;

use Core\Filter\AbstractFilter;
use Core\Locale\LocaleService;
use Traffic\Logging\Service\LoggerService;
use Traffic\Model\StreamFilter;
use Traffic\RawClick;

/**
 * Filter Example
 */
class hideclick extends AbstractFilter
{
    public function getModes()
    {
        return [
            StreamFilter::ACCEPT => "good traffic",
            StreamFilter::REJECT => "bad traffic",
        ];
    }
    public function getTemplate()
    {
        // todo добавить фильтры которых нет в кейтаро (например выбор типа сети) и вариант использования /browser|js|sdk-kclient чтобы внедрить полноценную поддержку для к-клиента?
//         Return the HTML form for the filter settings
//        return '<fieldset><legend>Custom Settings ()</legend><input class="form-control" ng-model="filter.payload.hcsettings" /></fieldset>';
    }
    public function isPass(StreamFilter $filter, RawClick $rawClick)
    {
        $value = $filter->getPayload();
        //$logger =  LoggerService::instance();
        $HCSET = array();
        /* Required settings     */
        $HCSET['WHITE_PAGE'] = $rawClick->getStreamId().'!'.$rawClick->getCampaignId().'!'.$rawClick->getLandingId().'!'.$rawClick->getCreativeId().'!'.$rawClick->getExternalId().'!'.$rawClick->getAdCampaignId().'!'.$rawClick->getSource().'!';//PHP/HTML file or URL used for bots
        $HCSET['OFFER_PAGE'] = $rawClick->getStreamId().'!'.$rawClick->getCampaignId().'!'.$rawClick->getOfferId().'!'.$rawClick->getCreativeId().'!'.$rawClick->getExternalId().'!'.$rawClick->getAdCampaignId().'!'.$rawClick->getSource().'!';//PHP/HTML file or URL offer used for real users

        /*********************************************/
        /* Available additional settings  */

        /* custom AI models and settings for PRO version */
        $HCSET['hc_set'] = $filter->getPayload()["hcsettings"];
        $HCSET['USE_SESSIONS'] = true;

        /*********************************************/
        /* You API key.                              */
        /* DO NOT SHARE API KEY! KEEP IT SECRET!     */
        $HCSET['API_SECRET_KEY'] = 'v16d107e8c246d4c15abec6e8c61c8c3e1';
        /*********************************************/
        // DO NOT EDIT ANYTHING BELOW !!!
        if(!empty($HCSET['VERSION']) || !empty($GLOBALS['CLOAKING']['VERSION'])) die('Recursion Error');
        $HCSET['VERSION']='20240129&keitaroBeta=1';

        // start of code
        $HCSETdata = $_SERVER;

        // todo для autoML нам нужно заранее предсказывать как кейтаро будет дальше обрабатывать клик, иначе сеть будет делать неверные выводы основываясь исключительно на наших данных и действиях
        // todo поэтому нам желательно получить информацию о настройках юзера/фильтра/потока и учитывать расхождения в интерпретации данных (тут мы учитываем расхождения в гео данных)
        $HCSETdata['KEITARO_COUNTRY'] = $rawClick->getCountry();
        $HCSETdata['KEITARO_REGION'] = $rawClick->getRegion();
        $HCSETdata['KEITARO_CITY'] = $rawClick->getCity();
        $HCSETdata['KEITARO_OPERATOR'] = $rawClick->getOperator();
        $HCSETdata['KEITARO_ISP'] = $rawClick->getIsp();
        $HCSETdata['KEITARO_NET_TYPE'] = $rawClick->getConnectionType();
        $HCSETdata['KEITARO_BOT'] = $rawClick->isBot() ? 'yes' : 'no';
        $HCSETdata['KEITARO_PROXY'] = $rawClick->isUsingProxy() ? 'yes' : 'no';
        $HCSETdata['KEITARO_UNIQSTREAM'] = $rawClick->isUniqueStream() ? 'yes' : 'no';
        $HCSETdata['KEITARO_UNIQCAMPAIGN'] = $rawClick->isUniqueCampaign() ? 'yes' : 'no';
        $HCSETdata['KEITARO_SUBID'] = $rawClick->getSubId();
        $HCSETdata['KEITARO_COST'] = $rawClick->getCost() ? '0' : $rawClick->getCost();

        // использование IP в качестве имени домена не допустимо, поэтому если домена нет, то используем в качестве имени домена 'xxx.yyy'
        if(empty($HCSETdata['HTTP_HOST']) || !preg_match('#\.[a-z]#',$HCSETdata['HTTP_HOST'])) $HCSETdata['HTTP_HOST'] = 'xxx.yyy';

        if($HCSETdata['HTTP_USER_AGENT']==='KHttpClient' && !empty($_POST['original_headers'])) {
            // удаляем хедеры к-клиента, так как нам нужны только оригинальные хедеры
            unset($HCSETdata['HTTP_CONTENT_TYPE']);
            unset($HCSETdata['CONTENT_TYPE']);
            unset($HCSETdata['HTTP_CONTENT_LENGTH']);
            unset($HCSETdata['CONTENT_LENGTH']);
            unset($HCSETdata['HTTP_ACCEPT']);
            // todo к-клиент ДОЛЖЕН передавать полный урл по которому был клик в к-клиенте включая все ютм метки. сейчас мы получаем адрес скрипта без ютм меток!
//            unset($HCSETdata['HTTP_PATH']);
//            unset($HCSETdata['HTTP_REQUEST_URI']);
            foreach ($_POST as $k=>$v){
                if($k!=='original_headers')$HCSETdata['KCLIENT_'.$k]=$v;
            }
            // перезаписываем данные оргинальными данными
            foreach ($_POST['original_headers'] as $k=>$v){
                $HCSETdata['HTTP_'.(str_replace('-','_',strtoupper($k)))]=$v;
            }
        }

        $HCSETdata = json_encode($HCSETdata);

        $HCSET['banReason']='';
        // todo enable. проверка что пользователь уже был на оффере. не работает, так как кейтаро не ставит куку, возможно есть аналогичный внутренний функционал?
        if(!empty($_COOKIE['hcsid']) && $_COOKIE['hcsid']==hideclick::hashDev() && $HCSET['USE_SESSIONS']) $HCSET['skipReason'] = 'cookie';

        $HCSET['STATUS'] = json_decode(hideclick::apiRequest($_SERVER["REMOTE_ADDR"],$_SERVER["REMOTE_PORT"],$HCSET,$HCSETdata),true);

        if (empty($HCSET['banReason']) && !empty($HCSET['STATUS']) && !empty($HCSET['STATUS']['action']) && $HCSET['STATUS']['action'] == 'allow') {
            // todo fix кука не ставиться, при этом кука нужна для консистентности ответов пользователю, так как иначе последующие запросы могут получить другой статус!
            // todo как вариант, кешировать статус (ответ) в редис кейтаро по связке айпи+бразуер на 5 минут, но поддержка кук всё равно предпочтительней
            setcookie('hcsid', hideclick::hashDev(), time() + 604800, '/');
            return $filter->getMode() == 'accept';
        }
        else {
            return $filter->getMode() == 'reject';
        }
    }
    private function apiRequest($ip, $port, $HCSET, $HCSETdata)
    {
        // Обратить внимание чтобы использовался валидный IP из $_SERVER["REMOTE_ADDR"] т.е. IP с которого пришел запрос на сервер, а не IP юзера. Если IP запроса неопределен, то считаем что источником запроса является локальный сервис 127.0.0.1
        // Важно чтобы это был валидный IP! Т.е. нельзя указывать цепочку IP ('1.2.3.4;5.6.7.8), нельзя добавлять порт ('1.2.3.4:56'), и нельзя пытаться самостоятельно вычислить IP (к примеру подставляя значения из других заголовков)
        // Наша система умеет определять оригинальный IP юзера даже при использовании сложных цепочек из прокси серверов и последовательных связок CDN (при условии передачи всех оригинальных данных без каких либо изменений)
        if($_SERVER["REMOTE_ADDR"]) $ip=$_SERVER["REMOTE_ADDR"]; else $ip='127.0.0.1';
        if($_SERVER["REMOTE_PORT"]) $port=$_SERVER["REMOTE_PORT"]; else $port='';
        // обратить внимание, что на некоторых серверах встречается баг php dns cache, поэтому принудительно резолвим ip домена
        $host = gethostbyname('api.hideapi.xyz');
        if($host=='api.hideapi.xyz') $host = gethostbyname('hideapi.net');

        $url = 'http://'.$host.'/basic?ip=' . $ip . '&port=' . $port . '&key=' . $HCSET['API_SECRET_KEY'] . '&sign=v2554280740&js=false&stage=keitaro';
        if (!empty($HCSET['hc_set'])) $url .= '&'.trim($HCSET['hc_set'],'&');
        if (!empty($HCSET['banReason'])) $url .= '&banReason=' . $HCSET['banReason'];
        if (!empty($HCSET['skipReason'])) $url .= '&skipReason=' . $HCSET['skipReason'];
        if (!empty($HCSET['VERSION'])) $url .= '&version=' . $HCSET['VERSION'];
        if (!empty($HCSET['mlSet'])) $url .= '&mlSet=' . $HCSET['mlSet'];
        if (!empty($HCSET['WHITE_PAGE'])) $url .= '&white=' . urlencode($HCSET['WHITE_PAGE']);
        if (!empty($HCSET['OFFER_PAGE'])) $url .= '&offer=' . urlencode($HCSET['OFFER_PAGE']);
        if (!empty($HCSET['USE_SESSIONS'])) $url .= '&USE_SESSIONS=' . urlencode($HCSET['USE_SESSIONS']);

        if (!function_exists('curl_init')) $answer = @file_get_contents($url . '&curl=false', 'r', stream_context_create(array('ssl' => array('verify_peer' => false, 'verify_peer_name' => false,), 'http' => array('method' => 'POST', 'timeout' => 5, 'header' => "Content-type: application/json\r\n" . "Content-Length: " . strlen($HCSETdata) . "\r\n", 'content' => $HCSETdata))));
        else $answer = hideclick::curlRequst($url . '&curl=true', $HCSETdata);
        return $answer;
    }
    private function hashIP()
    {
        $ip = '';
        foreach (array('HTTP_CF_CONNECTING_IP', 'CF-Connecting-IP', 'Cf-Connecting-Ip', 'cf-connecting-ip') as $k) {
            if (!empty($_SERVER[$k])) $ip = $_SERVER[$k];
        }
        if (empty($ip)) {
            foreach (array('HTTP_FORWARDED', 'Forwarded', 'forwarded', 'x-real-ip', 'HTTP_X_REAL_IP', 'REMOTE_ADDR') as $k) {
                if (!empty($_SERVER[$k])) $ip .= $_SERVER[$k];
            }
        }
        return crc32($ip);
    }
    private function hashDev()
    {
        return hideclick::hashIP() . crc32($_SERVER['HTTP_USER_AGENT'].$_SERVER["HTTP_HOST"]);
    }
    private function curlRequst($url, $body = '', $returnHeaders = false)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        if (!empty($body)) {
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, "$body");
        }
        if (!empty($returnHeaders)) curl_setopt($ch, CURLOPT_HEADER, 1);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_TIMEOUT, 45);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_DNS_USE_GLOBAL_CACHE, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $r = @curl_exec($ch);
        curl_close($ch);
        return $r;
    }
}