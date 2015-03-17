<?php
/**
 * Senat Parser. (c) 2014/2015 Jakub Król, Fundacja Media 3.0 ( http://media30.pl )
 * @ver 1.2
 */

namespace epforgpl\parsers;

require_once(dirname(dirname(__FILE__)) . '/vendor/simple_html_dom.php');
require_once('names.polish.php');

class ParserException extends \Exception {
}

class NetworkException extends \Exception {
}

class Senat {
    const BASE_URL = 'http://senat.gov.pl';

    private $cache;

    public function __construct() {
        if (defined('CACHE_ENABLED') ? CACHE_ENABLED : false) {
            $this->cache = new \Gilbitron\Util\SimpleCache();

            $this->cache->cache_path = defined('CACHE_PATH') ? CACHE_PATH : '.cache/';
            $this->cache->cache_time = defined('CACHE_TTL') ? CACHE_TTL : 3600;

            if (!is_dir($this->cache->cache_path)) {
                mkdir($this->cache->cache_path, 0755, true);
            }
            $this->debug('Using cache in ' . $this->cache->cache_path);
        }

        $this->names = new \parldata\names\Polish();
    }

    /**
     * Send a POST requst using cURL
     * @param string $url to request
     * @param array $post values to send
     * @param array $options for cURL
     * @return string
     */
    private function curl_post($url, array $post = array(), array $options = array()) {
        if ($this->cache) {
            $cache_id = sha1(json_encode(array('url' => $url, 'post' => $post)));
            if ($this->cache->is_cached($cache_id))
                return $this->cache->get_cache($cache_id);
        }

        $defaults = array(
            CURLOPT_POST => 1,
            CURLOPT_HEADER => 0,
            CURLOPT_URL => $url,
            CURLOPT_FRESH_CONNECT => 1,
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_FORBID_REUSE => 1,
            CURLOPT_TIMEOUT => 4,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (SenatParser; Fundacja Media 3.0) Gecko/20100101 Firefox/31.0',
            CURLOPT_POSTFIELDS => http_build_query($post)
        );

        $ch = curl_init();
        curl_setopt_array($ch, ($options + $defaults));
        if (!$result = curl_exec($ch)) {
            throw new NetworkException('CURL error: ' . curl_error($ch));
        }
        curl_close($ch);

        if ($this->cache) {
            $this->cache->set_cache($cache_id, $result);
        }
        return $result;
    }

    /**
     * Send a GET requst using cURL
     * @param string $url to request
     * @param array $get values to send
     * @param array $options for cURL
     * @return string
     */
    private function curl_get($url, array $get = array(), array $options = array()) {
        if ($this->cache) {
            $cache_id = sha1(json_encode(array('url' => $url, 'get' => $get)));
            if ($this->cache->is_cached($cache_id)) {
                if (defined('DEBUG') and DEBUG) {
                    $this->debug(" from cache $url");
                }

                return $this->cache->get_cache($cache_id);
            }
        }
        if (defined('DEBUG') and DEBUG) {
            $this->debug("downloading $url");
        }

        $defaults = array(
            CURLOPT_URL => $url . (strpos($url, '?') === FALSE ? '?' : '') . http_build_query($get),
            CURLOPT_HEADER => 0,
            CURLOPT_RETURNTRANSFER => TRUE,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (SenatParser; Fundacja Media 3.0) Gecko/20100101 Firefox/31.0',
            CURLOPT_TIMEOUT => 4
        );

        $ch = curl_init();
        curl_setopt_array($ch, ($options + $defaults));
        if (!$result = curl_exec($ch)) {
            throw new NetworkException('CURL error: ' . curl_error($ch));
        }
        curl_close($ch);

        if ($this->cache) {
            $this->cache->set_cache($cache_id, $result);
        }
        return $result;
    }

    /**
     * This will turn array 90 degrees. For example: [['a',1,true], ['b', 2, false]] will become [['a', 'b'], [1, 2], [true, false]]. Use it with matching.
     * @param array $m Input array
     * @return array $m Turned 90 degrees
     */
    private function turn_array($m) {
        $rt = array();
        for ($z = 0; $z < count($m); $z++) {
            for ($x = 0; $x < count($m[$z]); $x++) {
                $rt[$x][$z] = $m[$z][$x];
            }
        }

        return $rt;
    }

    /**
     * Normal trim, just added some chars to default.
     * @param string $s
     * @param string $chars
     * @return string Trimmed $s.
     */
    private function trimEx($s, $chars = " \t\r\n") {
        return trim($s, $chars);
    }

    /**
     * Copy string from some character to some another character
     * @param string $s
     * @param string|int|null $from Set to NULL to copy from the beginning, set to some INTEGER value to copy from char-index.
     * @param string|int|null $to Set to NULL to copy till the end, set to some INTEGER value to copy characters count.
     * @return string $s[$from - $to]
     */
    private function cpyFromTo($s, $from = NULL, $to = NULL) {
        if (!is_null($from)) {
            if (is_int($from))
                $s = mb_substr($s, $from);
            else
                $s = mb_substr($s, mb_strpos($s, $from) + mb_strlen($from));
        }
        if (!is_null($to)) {
            if (is_int($to))
                $s = mb_substr($s, 0, $to);
            else
                $s = mb_substr($s, 0, mb_strpos($s, $to));
        }
        return $s;
    }

    /**
     * This will remove double spaces and tabs. For example "one    two" will become "one two".
     * @param string $s
     * @param bool $andTrimEx Set to true (default) to begin with $this->trimEx($s) call.
     * @return string $s without double spaces
     */
    private function removeDblSpaces($s, $andTrimEx = TRUE) {
        if ($andTrimEx)
            $s = $this->trimEx($s);
        $s = str_replace("\t", ' ', $s);
        while (strpos($s, '  ') !== false)
            $s = str_replace('  ', ' ', $s);
        return $s;
    }

    private function selectFrom($subject, $regEx, $group, $default = '') {
        $matches = array();
        preg_match_all($regEx, $subject, $matches);

        if ((empty($matches)) || (!is_array($matches[$group])) || (empty($matches[$group])))
            throw new ParserException("Couldn't select $regEx group($group) from $subject");
        else
            return $matches[$group][0];
    }

    private function tryToFind1($subject, $regEx, $group, $default = null) {
        $matches = array();
        preg_match_all($regEx, $subject, $matches);
        if ((empty($matches)) || (!is_array($matches[$group])) || (empty($matches[$group])))
            return $default;
        else
            return $matches[$group][0];
    }

    /**
     * This will extract OKW (area where he was elected) from senator`s info page html.
     * @param string $html
     * @return string Empty if not found.
     */
    private function _senatorExtractOKW($html) {
        $re = "/<div class=\"informacje\">[\\s\\S]*?<li>(Okręg ([^<]*))<\\/li>/";

        $m = $this->tryToFind1($html, $re, 1);
        return $m;
    }

    private function _senatorExtractMandateEndDate($html) {
//        if($first_info = $dom->find('.informacje ul li', 0)) {
//            $text = $this->removeDblSpaces($first_info->plaintext, true);
//            if (preg_match('/^Mandat/i', $text) or preg_match('/^Zmarł/i', $text)) {
//                $matches = array();
//                if (preg_match('/[\d\.]+/', $text, $matches)) {
//                    return vsprintf('%4d-%02d-%02d', array_reverse(explode('.', $matches[0])));
//
//                } else {
//                    throw new ParserException("Couldn't find date in: " . $text);
//                }
//            }
//        }

        $m1 = $this->tryToFind1($html, "/<div class=\"informacje\">[\\s\\S]*?<li>[\"\\s\\S]*(Zmarł([^<]*))<\\/li>/i", 1);
        $m2 = $this->tryToFind1($html, "/<div class=\"informacje\">[\\s\\S]*?<li>[\"\\s\\S]*(Mandat ([^<]*))<\\/li>/i", 1);

        if (!empty($m2)) {
            $m1 = $m2;
        }
        if (!empty($m1)) {
            $end_date_info = trim($this->removeDblSpaces($m1));
            $matches = array();
            if (preg_match('/[\d\.]+/', $end_date_info, $matches)) {
                return vsprintf('%4d-%02d-%02d', array_reverse(explode('.', $matches[0])));

            } else {
                throw new ParserException("Couldn't find date in: " + $end_date_info);
            }
        }

        return null;
    }

    /**
     * This will extract WWW from senator`s info page html.
     * @param string $html
     * @return string Empty if not found.
     */
    private function _senatorExtractWWW($html) {
        $re = "/<div class=\"informacje\">[\\s\\S]*?<li>[\\s\\S]*?WWW:[^\"]*?\"([^\"]*)\"[\\s\\S]*?<\\/li>/";

        $m = $this->tryToFind1($html, $re, 1);
        return $m;
    }

    /**
     * This will extract cadencies from senator`s info page html.
     * @param string $html
     * @return array Empty if not found.
     */
    private function _senatorExtractCadencies($html) {
        $re = "/<div class=\"informacje\">[\\s\\S]*?<li>(Kadencje:([^<]*))<\\/li>/";

        $m = $this->trimEx($this->tryToFind1($html, $re, 2));
        if ($m == '')
            $m = array();
        else
            $m = explode(', ', $m);
        return $m;
    }

    /**
     * This will extract e-mail from senator`s info page html.
     * @param string $html
     * @return string Empty if not found.
     */
    private function _senatorExtractEmail($html) {
        $re = "/<div class=\"informacje\">[\\s\\S]*?<li>[\\s\\S]*?E-mail:[^<]*?<script type=\"text\\/javascript\">[^S]*SendTo\\('[^']*?', '[^']*?', '([^']*)', '([^']*)', [\\s\\S]*?<\\/script>[\\s\\S]*?<\\/li>/";

        $matches = array();
        preg_match_all($re, $html, $matches);
        if ((empty($matches)) || (empty($matches[0])))
            return array();
        $matches = $this->turn_array($matches);

        if ((empty($matches)) || (count($matches[0]) < 2))
            return '';
        else
            return $matches[0][1] . '@' . $matches[0][2];
    }

    /**
     * This will extract clubs from senator`s info page html.
     * @param string $html
     * @return array Empty if not found.
     */
    private function _senatorExtractClubs($html) {
        if (strpos($html, '<div class="kluby">') === false)
            return array();
        $html = $this->cpyFromTo($html, '<div class="kluby">', '</div>');

        $re = "/<p><a href=\"(([^#]*)#klub-([\\d]*))\" title=\"([^\"]*)\"[^<]*?<\\/a><\\/p>/";

        $matches = array();
        preg_match_all($re, $html, $matches);
        $matches = $this->turn_array($matches);

        if ((empty($matches)) || (count($matches[0]) < 5))
            return array();

        $clubs = array();

        foreach ($matches as $match) {
            $clubs[$match[3]] = array(
                'id' => $match[3],
                'name' => $match[4],
                'url' => self::BASE_URL . $match[1]
            );
        }

        return $clubs;
    }

    /**
     * This will extract Biographic Note from senator`s info page html.
     * @param string $html
     * @return string Empty if not found.
     */
    private function _senatorExtractBioNote($html) {
        // $re = "/<div class=\"sekcja-2\">[^<]*?<p style=\"text-align: justify;\">([\\s\\S]*)<\\/div>[^<]*?<div class=\"sekcja-2\">/";
        $re = "/<div class=\"sekcja-2\">[^<]*?([\\s\\S]*)<\\/div>[^<]*?<div class=\"sekcja-2\">/";

        try {
            $m = $this->selectFrom($html, $re, 1);
        } catch (ParserException $ex) {
            throw new ParserException("Error while parsing bionote", 0, $ex);
        }
        $m = $this->removeDblSpaces(strip_tags($m));

        return $m;
    }

    /**
     * This will extract employees and cooperators from senator`s info page html.
     * @param string $html
     * @return array Empty if not found.
     */
    private function _senatorExtractEmployees($html) {
        if (strpos($html, '<h3>Pracownicy i współpracownicy</h3>') === false)
            return array();
        $html = $this->cpyFromTo($html, '<h3>Pracownicy i współpracownicy</h3>', '<div class="js-content komisje">');

        $re = "/<div>[\\s\\S]*?<a href=\"([^\"]*)\">([^<]*)<\\/a>[^<]*?<\\/div>/";

        $matches = array();
        preg_match_all($re, $html, $matches);
        if ((empty($matches)) || (empty($matches[0])))
            return array();

        $matches = $this->turn_array($matches);

        if ((empty($matches)) || (count($matches[0]) < 3))
            return array();

        $employees = array();

        foreach ($matches as $match) {
            $employees[] = array(
                'name' => $match[2],
                'url' => self::BASE_URL . $match[1]
            );
        }

        return $employees;
    }

    /**
     * This will extract different teams from senator`s info page html.
     * @param string $html
     * @param string $from Beginning of team`s HTML
     * @param string $to End of team`s HTML
     * @return array Empty if not found.
     */
    private function __senatorExtractTeams($html, $from, $to) {
        if (strpos($html, $from) === false)
            return array();
        $html = $this->cpyFromTo($html, $from, $to);

        $re = "/<li>[^<]*?<a href=\"([^,]*,([\\d]*),[^\"]*)\">([^<]*)<\\/a>([\\s\\S]*?)<p>([^<]*?)<\\/p>[\\s\\S]*?<\\/li>/";

        $matches = array();
        preg_match_all($re, $html, $matches);
        $matches = $this->turn_array($matches);

        if ((empty($matches)) || (count($matches[0]) < 6))
            return array();

        $team = array();

        foreach ($matches as $match) {
            $org = array(
                'id' => $match[2],
                'name' => $match[3],
                'url' => self::BASE_URL . $match[1],
                'notes' => $this->removeDblSpaces(strip_tags($match[4])),
            );

            $_dates = $this->removeDblSpaces(strip_tags($match[5]));
            if (!empty($_dates)) {
                $matches = array();
                $hit = false;
                if (preg_match('/Od:\\s*(\\d{1,2}\\.\\d{1,2}\\.\\d{4})\\s+r\\./iu', $_dates, $matches)) {
                    $hit = true;
                    $ds = explode('.', preg_replace('/\\s+/', '', $matches[1]));
                    $date = $ds[2] . '-' . str_pad($ds[1], 2, '0', STR_PAD_LEFT) . '-' . str_pad($ds[0], 2, '0', STR_PAD_LEFT);

                    $org['start_date'] = $date;
                }

                if (preg_match('/Do:\\s*(\\d{1,2}\\.\\d{1,2}\\.\\d{4})\\s+r\\./iu', $_dates, $matches)) {
                    $hit = true;
                    $ds = explode('.', preg_replace('/\\s+/', '', $matches[1]));
                    $date = $ds[2] . '-' . str_pad($ds[1], 2, '0', STR_PAD_LEFT) . '-' . str_pad($ds[0], 2, '0', STR_PAD_LEFT);

                    $org['end_date'] = $date;
                }

                if (!$hit) {
                    throw new ParserException("Unrecognized date format: " . $_dates);
                }
            }

            $team[$match[2]] = $org;
        }

        return $team;
    }

    /**
     * This will extract commissions from senator`s info page html.
     * @param string $html
     * @return array Empty if not found.
     */
    private function _senatorExtractCommissions($html) {
        return $this->__senatorExtractTeams($html, '<div class="js-content komisje">', '</ul>');
    }

    /**
     * This will extract parliamentary assemblies from senator`s info page html.
     * @param string $html
     * @return array Empty if not found.
     */
    private function _senatorExtractParlimentaryAssemblies($html) {
        return $this->__senatorExtractTeams($html, '<p class="etykieta">Zespoły parlamentarne</p>', '</ul>');
    }

    /**
     * This will extract parliamentary assemblies from senator`s info page html.
     * @param string $html
     * @return array Empty if not found.
     */
    private function _senatorExtractSenatAssemblies($html) {
        return $this->__senatorExtractTeams($html, '<p class="etykieta">Zespoły senackie</p>', '</ul>');
    }

    public function updateSenatorSpeechesList($SenatorID) {
        $html = $this->curl_get(self::BASE_URL . '/sklad/senatorowie/aktywnosc,' . $SenatorID . ',8.html');

        $re = "/<tr>[^<]*?<td class=\"numer-posiedzenia\">([\\d]*?)<\\/td>[\\s\\S]*?<td class=\"data-aktywnosci nowrap\">([^<]*?)<\\/td>[^<]*?<td class=\"punkt\">([^<]*?)<\\/td>[^<]*?<td class=\"etapy\">([\\s\\S]*?)<\\/td>[^<]*?<\\/tr>/";

        $reAct = "/<p>[^<]*?<a[\\s\\S]*?href=\"\\/prace\\/senat\\/posiedzenia\\/przebieg,([^,]*),[\\d]*([^.]*?)\\.html#([^\"]*)\"[^>]*?>([^<]*)<\\/a>[^<]*?<\\/p>/";

        $matches = array();
        preg_match_all($re, $html, $matches);
        $matches = $this->turn_array($matches);

        $ar = array();
        // TODO handle no matches
        foreach ($matches as $match) {
            $activity = array();

            $actMatches = array();
            preg_match_all($reAct, $match[4], $actMatches);
            $actMatches = $this->turn_array($actMatches);
            foreach ($actMatches as $aMatch) {
                $activity[] = array(
                    'title' => $this->trimEx($aMatch[4]),
                    'is_in_stenogram' => $aMatch[2] == '',
                    'meeting_id' => $aMatch[1],
                    'speech_ref' => $aMatch[3]
                );
            }

            $ar[] = array(
                'no_of_meeting' => $match[1],
                'when' => $this->trimEx($match[2]),
                'title_of_agenda_item' => $this->trimEx($match[3]),
                'activity' => $activity
            );
        }

        return $ar;
    }

    public function urlSitting($id_sitting) {
        // TODO add ',1' if needed
        return self::BASE_URL . "/prace/senat/posiedzenia/tematy,$id_sitting.html";
    }

    public function urlSittingStenogram($id_sitting_with_day) {
        return self::BASE_URL . '/prace/senat/posiedzenia/przebieg,' . $id_sitting_with_day . '.html';
    }

    public function updateSenatorVotesAtMeeting($SenatorID, $MeetingID) {
        $html = $this->curl_get(self::BASE_URL . '/sklad/senatorowie/aktywnosc-glosowania,' . $SenatorID . ',8,szczegoly,' . $MeetingID . '.html');

        $re = "/<td>[^<]*?<a href=\"(\\/sklad\\/senatorowie\\/szczegoly-glosowania,([^,]*),([^,]*),8.html)\">[^<]*?<\\/a>[^<]*?<\\/td>[^<]*?<td>[^<]*?<\\/td>[^<]*?<td>([^<]*)<\\/td>/";

        $matches = array();
        preg_match_all($re, $html, $matches);
        $matches = $this->turn_array($matches);

        $ans = array();
        foreach ($matches as $match) {
            $ans[$match[2] . ',' . $match[3]] = array(
                'voting_id' => $match[2] . ',' . $match[3],
                'vote' => $this->trimEx($match[4])
            );
        }
        return $ans;
    }

    //Vote might me: za/przeciw/wstrzymał się/nie głosował/nieobecny
    public function updateSenatorVotingActivity($SenatorID) {
        $html = $this->curl_get(self::BASE_URL . '/sklad/senatorowie/aktywnosc-glosowania,' . $SenatorID . ',8.html');

        $re = "/<td class=\"nowrap\">[^<]*?<a href=\"(\\/sklad\\/senatorowie\\/aktywnosc-glosowania,[\\d]*,8,szczegoly,([^.]*)\\.html)\">[^<]*?<\\/a>[^<]*?<\\/td>/";

        $matches = array();
        preg_match_all($re, $html, $matches);
        $matches = $this->turn_array($matches);

        $ans = array();
        foreach ($matches as $match) {
            $ans[$match[2]] = $this->updateSenatorVotesAtMeeting($SenatorID, $match[2]);
        }
        return $ans;
    }

    public function updateSenatorInfo($SenatorID) {
        $url = self::BASE_URL . '/sklad/senatorowie/senator,' . $SenatorID . '.html';
        $html = $this->curl_get($url);

        try {
            $answer = array(
                'id' => $SenatorID,
                'okw' => $this->_senatorExtractOKW($html),
                'mandate_end_date' => $this->_senatorExtractMandateEndDate($html),
                'cadencies' => $this->_senatorExtractCadencies($html),
                'email' => $this->_senatorExtractEmail($html),
                'www' => $this->_senatorExtractWWW($html),
                'clubs' => $this->_senatorExtractClubs($html),
                // TODO delete bionote missing for id = 100, 101, ..
                'bio_note' => $this->_senatorExtractBioNote($html),
                'statements_of_assets_and_record_of_benefits' => self::BASE_URL . '/sklad/senatorowie/oswiadczenia,' . $SenatorID . ',8.html',
                'employees_cooperates' => $this->_senatorExtractEmployees($html),
                'committees' => $this->_senatorExtractCommissions($html),
                'parliamentary_assemblies' => $this->_senatorExtractParlimentaryAssemblies($html),
                'senat_assemblies' => $this->_senatorExtractSenatAssemblies($html),
                'activity_senat_meetings' => $this->updateSenatorSpeechesList($SenatorID),
                'senator_statements' => self::BASE_URL . '/sklad/senatorowie/oswiadczenia-senatorskie,' . $SenatorID . ',8.html',
                'source' => $url
            );
            $answer['birth_date'] = $this->_senatorExtractBirthDate($answer['bio_note']);
            $answer['checksum'] = $this->getChecksumForInfo($answer);

            return $answer;

        } catch(ParserException $ex) {
            throw new ParserException("Error while parsing $url", 0, $ex);
        }
    }

    public function urlSenatorsList() {
        return self::BASE_URL . '/sklad/senatorowie/';
    }

    public function updateSenatorsList() {
        $html = $this->curl_get($this->urlSenatorsList());

        $re = "/<div class=\"senator-kontener\"[^<]*<div class=\"zdjecie\">[^<]*?<img src=\"([^\"]*?)\"[^<]*?<\\/div>[\\s\\S]*?<a href=\"\\/sklad\\/senatorowie\\/senator,([^\"]*)\">([^<]*)<\\/a>[\\s\\S]*?(<p class=\"adnotacja\">([^<]*)<\\/p>| )/"; //

        $base_info_matches = array();
        preg_match_all($re, $html, $base_info_matches);
        $base_info_matches = $this->turn_array($base_info_matches);

        $answer = array();

        foreach ($base_info_matches as $info) {
            $id = $this->cpyFromTo($info[2], NULL, ',');

            $answer[$id] = array_merge($this->parseFullName($info[3]), array(
                'id' => $id,
                'photo' => self::BASE_URL . $info[1],
                'url' => self::BASE_URL . '/sklad/senatorowie/senator,' . $info[2]
            ));

            $answer[$id]['gender'] = $this->names->mapGender($answer[$id]['given_name']);

            $end_date_info = trim($this->removeDblSpaces($info[5]));
            if (!empty($end_date_info)) {
                $matches = array();
                if (preg_match('/[\d\.]+/', $end_date_info, $matches)) {
                    $answer[$id]['end_date'] = vsprintf('%4d-%02d-%02d', array_reverse(explode('.', $matches[0])));

                } else {
                    throw new ParserException("Couldn't find date in: " + $end_date_info);
                }
            }
        }

        if (!(empty($this->names->guesses))) {
            error_log(var_export($this->names->guesses));
            throw new ParserException("Had to guess gender of some names. Check dictionary shown above and add it to names.polish.php.");
        }

        return $answer;
    }

    /**
     * This will create md5 hash check-sum for input array, to save its current state
     * @param $inArray Original input array
     * @return string MD5 check-sum.
     */
    public function getChecksumForInfo($inArray) {
        return md5(json_encode($inArray));
    }

    public function updateSenatorsAll() {
        $list = $this->updateSenatorsList();

        foreach ($list as &$senator) {
            $senator['info'] = $this->updateSenatorInfo($senator['id']);
        }

        return $list;
    }

    /**
     * This will extract one speech from stenogram (from ::updateMeetingStenogram method), using given $speechRel.
     * @param string $meetingTxt Output of ::updateMeetingStenogram
     * @param string $speechRel Speech rel of Senator`s speech.
     * @param bool $removeMarszalekEtc When true (default) it will try to remove speeches of Marszałek and Wicemarszałek (as it would be introduce to next speech).
     * @return string
     */
    public function extractSpeechRelFromStenogram($meetingTxt, $speechRel, $removeMarszalekEtc = true) {
        $meetingTxt = $this->cpyFromTo($meetingTxt, '<h3 class="speech-rel" id="' . $speechRel . '" rel="' . $speechRel . '"></h3>');
        if (strpos($meetingTxt, '<h3 class="speech-rel"') !== FALSE)
            $meetingTxt = $this->cpyFromTo($meetingTxt, null, '<h3 class="speech-rel"');

        if ($removeMarszalekEtc) {
            if (strpos($meetingTxt, ".\r\nMarszałek ") !== FALSE)
                $meetingTxt = $this->cpyFromTo($meetingTxt, null, ".\r\nMarszałek ") . '.';
            if (strpos($meetingTxt, ".\r\nWicemarszałek ") !== FALSE)
                $meetingTxt = $this->cpyFromTo($meetingTxt, null, ".\r\nWicemarszałek ") . '.';
            if (strpos($meetingTxt, ".\r\n(Przewodnictwo ") !== FALSE)
                $meetingTxt = $this->cpyFromTo($meetingTxt, null, ".\r\n(Przewodnictwo ") . '.';
        }

        $meetingTxt = $this->trimEx($meetingTxt);

        return $meetingTxt;
    }

    /**
     * Returns DOMs of stenogram of given sitting
     *
     * @param $identifier
     */
    public function getStenogram($id_sitting_with_day) {
        $url = $this->urlSittingStenogram($id_sitting_with_day);
        $doc = $this->curl_get($url);
        try {
            $dom = str_get_html($doc);
        } catch (\Exception $ex) {
            throw new ParserException("Error creating DOM of $url", 0, $ex);
        }

        $ret = array(
            'source' => $url,
            'node' => $dom->find('#jq-stenogram-tresc', 0)
        );

        if (!$ret['node']) {
            throw new ParserException("Couldn't find #jq-stenogram-tresc in $url");
        }

        return $ret;
    }

    /**
     * This will get full stenogram text from meeting.
     * IMPORTANT NOTE: Stenogram will include only one HTML tag: '<h3 class="speech-rel" id="$SPEECH_REL" rel="$SPEECH_REL"></h3>' - everywhere where Senator took a voice, use it to navigate through speeches. DO NOT remove it from database in case to use ::extractSpeechRelFromStenogram.
     * @param string $meetingID Meeting ID.
     * @return string Text, witouh double spaces, HTML etc.
     */
    public function updateMeetingStenogram($meetingID) {
        $text = "\r\n";
        $i = 0;
        do {
            $i++;
            $html = $this->curl_get(self::BASE_URL . '/prace/senat/posiedzenia/przebieg,' . $meetingID . ',' . $i . '.html');

            $re = "/<div id=\"jq-stenogram-tresc\">([\\s\\S]*?)<script type=\"text\\/javascript\" src=\"\\/szablony\\/senat\\/scripts\\/jquery.colorbox-min.js\"><\\/script>/";

            $tmp = $this->tryToFind1($html, $re, 1);
            if (!empty($tmp)) {
                $tmp = str_replace('</p>', "\r\n", $tmp);
                $repRe = <<<REGEX
/<h3 rel="([^"]*?)"[^>]*?>/
REGEX;
                $sprelRe = <<<REGEX
/\[SPEECH_REL="([^"]*?)"\]/
REGEX;
                $tmp = preg_replace($repRe, '[SPEECH_REL="$1"]', $tmp);
                $tmp = strip_tags($tmp);
                $tmp = preg_replace($sprelRe, '<h3 class="speech-rel" id="$1" rel="$1"></h3>', $tmp);
                $tmp = $this->removeDblSpaces($tmp);
                $text .= $tmp;
            }

        } while (strpos($html, ' class="link-stenogram-nastepny-dzien"') !== FALSE);

        return $this->trimEx($text);
    }

    // http://senat.gov.pl/prace/senat/posiedzenia/glosowanie-drukuj,368.html
    public function updatePeopleVotes($url) {
        $html = $this->curl_get($url);
        $dom = str_get_html($html);

        $grouped = array();
        $people = array();

        if ($podtytul = dNavigateOptional($dom, 'h4.podtytul', 0)) {
            if ($podtytul->plaintext == 'Głosowanie anulowane') {
                return array(
                    'grouped' => $grouped,
                    'votes' => $people
                );
            }
        }

        $map = array(
            'obecnych' => 'present',
            'głosowało' => 'present',
            'za' => 'yes',
            'przeciw' => 'no',
            'wstrzymało się' => 'abstain',
            'nie głosowało' => 'not voting'
        );

        $mapDetails = array(
            'obecnych' => 'present',
            'za' => 'yes',
            'przec.' => 'no',
            'wstrz.' => 'abstain',
            'nie gł.' => 'not voting',
            'nieob.' => 'absent'
        );

        foreach(dAtLeast($dom, 'div.ogolne-wyniki span', 1) as $group) {
            $matches = array();
            if (!preg_match("/([\w\s]+):\s*(\d+)/iu", $group->plaintext, $matches)) {
                throw new ParserException("Couldn't parse " . $group->plaintext);
            }
            $type = trim($matches[1]);
            if (!has_key($map, $type)) {
                throw new ParserException("Unknown vote type: $type");
            }

            $grouped[$map[$type]] = intval($matches[2]);
        }

        foreach(dAtLeast($dom, '.glosy-senatorow .senator', 1) as $senator) {
            $s = dExactlyOne($senator, '.dane')->plaintext;
            $v = dExactlyOne($senator, '.glos')->plaintext;

            if (!has_key($mapDetails, $v)) {
                throw new ParserException("Unknown details vote type: $v");
            }
            $v = $mapDetails[$v];

            $ss = preg_split('/\s/', $s, 0, PREG_SPLIT_NO_EMPTY);
            if (count($ss) != 2) {
                throw new ParserException("Was expecting two words in $s");
            }
            $initials = preg_split('/\\./', $ss[0], 0, PREG_SPLIT_NO_EMPTY);

            array_push($people, array(
                'vote' => $v,
                'family_name' => $ss[1],
                'initials' => $initials
            ));
        }

        return array(
            'grouped' => $grouped,
            'votes' => $people
        );
    }

    public function urlMeetingsVotings($meetingID, $i) {
        $source_ids = explode(',', $meetingID);
        return self::BASE_URL . '/prace/senat/posiedzenia/przebieg,' . $source_ids[0] . ',' . $i . ',glosowania.html';
    }

    public function updateMeetingVotings($meetingID) {
        $ans = array();

        $i = 0;
        do {
            $i++;
            $url = $this->urlMeetingsVotings($meetingID, $i);
            $html = $this->curl_get($url);

            // check for no votings
            if (strpos($html, 'W tym dniu nie odbyły się żadne głosowania')) {
                continue;
            }

            $dom = str_get_html($html);
            foreach (dAtLeast($dom, 'table.glosowania tr', 2) as $row) {
                if ($row->find('th')) {
                    continue;
                }

                $vote_event = array(
                    'no' => trim($row->find('td',0)->plaintext),
                    'day' => $i,
                    'results_people_url' => trim($row->find('td',2)->find('a',1)->href),
                    'results_clubs_url' => trim($row->find('td',3)->find('a',0)->href),
                    'source' => $url,
                );

                foreach($vote_event as $k => $v) {
                    if (!$v) {
                        throw new ParserException("Missing $k on $url");
                    }
                }

                $action = dNavigateOptional($row, 'td', 1, 'p.podpis',0);
                if ($action) {
                    $vote_event['action'] = $action = $action->plaintext;
                }

                // Motion (if exists)
                // Sometimes there are votings over sth proposed in sitting, as for example 11th voting here: http://senat.gov.pl/prace/senat/posiedzenia/przebieg,304,2,glosowania.html
                $motion = trim($row->find('td',1)->find('div',0)->plaintext);
                if ($motion) {
                    $vote_event['motion'] = $motion;

                } else {
                    // check if it is exception
                    if ($action != 'Wniosek formalny') {
                        throw new ParserException("Not specified motion and unknown action $action");
                    }
                }

                array_push($ans, $vote_event);
            }

        } while (strpos($html, ' class="link-stenogram-nastepny-dzien"') !== FALSE);

        return $ans;
    }

    /**
     * This will return list of all meetings
     * @return array ['id'=>ID of meeting, 'name'=>Name/title/subject of meeting. 'when'=>Date(s) of meeting(s)]
     */
    public function updateMeetingsList() {
        $answer = array();

        $i = 0;
        do {
            $i++;
            $html = $this->curl_get(self::BASE_URL . '/prace/senat/posiedzenia/page,' . $i . '.html');

            $re = "/<tr [^<]*?>[^<]*?<td class=\"pierwsza\">[\\s\\S]*?<a href=\"(([^,]*),([\\d]*?),([^\"]*))\"[^>]*?>([^<]*)<\\/a>[\\s\\S]*?<\\/td>[\\s\\S]*?<td>([^<]*)<\\/td>[\\s\\S]*?<td class=\"ostatnia\">[\\s\\S]*?<a class=\"stenogram-link\".*?href=\"([^\"]*)\"[^>]*?>[\\s\\S]*?<\\/tr>/";
            $base_info_matches = array();
            preg_match_all($re, $html, $base_info_matches);
            $base_info_matches = $this->turn_array($base_info_matches);

            foreach ($base_info_matches as $info) {
                $id = $info[3];
                $name = $this->trimEx($info[5]);


                $answer[$id] = array(
                    'id' => $id,
                    'name' => $name,
                    'topics_url'=>self::BASE_URL.$info[1],
                    'stenogram_url'=>self::BASE_URL.$info[7]
                );

                // Sitting's no.
                $number = array();
                if (!preg_match('/^(\d+)/', $name, $number)) {
                    throw new ParserException("Cannot parse sitting's number from name: " . $name);
                }
                $answer[$id]['number'] = $number[0];

                // Dates sitting has been taking place
                $dates_txt = $this->trimEx($info[6]);
                $dmatches = array();
                if (!preg_match('/^([\\d\\si,]+)(\\w+)\\s+(\\d{4})[\\sr\\.]*$/u', $dates_txt, $dmatches)) {
                    throw new ParserException("Error parsing sittings dates: " . $dates_txt);
                }

                $days = preg_split('/[^\d]/', $dmatches[1], null, PREG_SPLIT_NO_EMPTY);
                $month = $this->mapPlMonthDopelniacz($dmatches[2]);
                $year = $dmatches[3];

                $dates = array();
                foreach($days as $day) {
                    array_push($dates, sprintf('%4d-%02d-%02d', $year, $month, $day));
                }
                $answer[$id]['dates'] = $dates;
            }

        } while (strpos($html, '<div class="pager-nastepne">') !== FALSE);
        return $answer;
    }

    /**
     * Returns list of archival terms of office
     *
     * @return array
     * @throws Exception
     */
    public function getTermsOfOffice() {
        $url = self::BASE_URL . '/poprzednie-kadencje/';
        $html = str_get_html($this->curl_get($url));

        $terms = array();
        foreach($html->find('.aktualnosci-margines li') as $hterm) {
            // strip meaningless whitespaces
            $text = preg_replace("/\s+/", '', $hterm->plaintext);

            $matches = array();
            // example: VII kadencja (5.11.2007 r. - 7.11.2011 r.)
            if (preg_match('/([VIXMC]+)[^\(]+\(([\d\.]+)[^-]+\-([\d\.]+)/', $text, $matches)) {
                $from = explode('.', $matches[2]);
                $to = explode('.', $matches[3]);
                if (count($from) != 3 || count($to) != 3) {
                    throw new ParserException("Cannot parse dates in: " . $text);
                }

                array_push($terms, array(
                    'id' => $matches[1],
                    'start_date' => vsprintf('%4d-%02d-%02d', array_reverse($from)),
                    'end_date' => vsprintf('%4d-%02d-%02d', array_reverse($to)),
                    'source' => $url
                ));
            } else {
                throw new ParserException("Cannot match term of office archive: " . $text);
            }
        }

        return $terms;
    }

    private function debug($msg) {
        if (defined('DEBUG') and DEBUG) {
            echo $msg . "\n";
        }
    }

    private function _senatorExtractBirthDate($bio_note) {
        if ($bio_note == null) {
            return null;
        }

        $matches = array();
        if (!preg_match('/Urodził(a)? się (\d+)\s+(\w+)\s+(\d{4})/iu', $bio_note, $matches)) {
            throw new ParserException("Nieznana data urodzin: " . $bio_note);
        }

        try {
            $month = $this->mapPlMonthDopelniacz($matches[3]);
        } catch (\Exception $ex) {
            throw new ParserException($ex->getMessage() . ': ' . $bio_note, 0, $ex);
        }

        return $matches[4] . '-' . $month . '-' . str_pad($matches[2], 2, '0', STR_PAD_LEFT);
    }

    private function mapPlMonthDopelniacz($month) {
        $months = array(
            'stycznia' => '01',
            'lutego' => '02',
            'marca' => '03',
            'kwietnia' => '04',
            'maja' => '05',
            'czerwca' => '06',
            'lipca' => '07',
            'sierpnia' => '08',
            'września' => '09',
            'października' => '10',
            'paździenika' => '10',
            'listopada' => '11',
            'grudnia' => '12'
        );

        if (isset($months[$month])) {
            return $months[$month];
        }

        throw new ParserException("Unknown month: " . $month);
    }

    public function findIndexOfName($text) {
        foreach(\parldata\names\Polish::$dictionary as $name => $gender) {
            if (($pos = strpos($text, $name)) !== false) {
                return $pos;
            }
        }

        throw new ParserException("Couldn't find name in '$text'. Add it to names.polish.php");
    }

    public function parseFullName($name) {
        $names = preg_split("/\s+/", $name);
        if (count($names) > 3) {
            error_log("Multi-part name: " . $name);
        }

        $person = array(
            'name' => $name,
            'family_name' => array_pop($names),
            'given_name' => array_shift($names)
        );

        if (!empty($names)) {
            $person['additional_name'] = $names[0];
        }

        return $person;
    }
}

function dExactlyOne($node, $selector) {
    $res = $node->find($selector);
    if (!$res) {
        throw new ParserException("Missing element $selector");
    }
    if (count($res) > 1) {
        throw new ParserException("Too many elements $selector");
    }
    return $res[0];
}

function dAtLeast($node, $selector, $num) {
    $res = $node->find($selector);
    if (!$res) {
        throw new ParserException("Missing element $selector");
    }
    if (count($res) < $num) {
        throw new ParserException("Expected at least $num elements, got " . count($res) ." $selector");
    }
    return $res;
}

function dNavigateOptional() {
    $arr = func_get_args();
    $node = array_shift($arr);

    while(!empty($arr)) {
        if (count($arr) >= 2) {
            $node = $node->find($arr[0], $arr[1]);
            array_shift($arr); array_shift($arr);

        } else {
            $node = $node->find($arr[0]);
            array_shift($arr);
        }

        if ($node == null) {
            return null;
        }
    }

    return $node;
}