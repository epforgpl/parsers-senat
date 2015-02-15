<?php
/**
 * Senat Parser. (c) 2014/2015 Jakub Król, Fundacja Media 3.0 ( http://media30.pl )
 * @ver 1.2
 */


class SenatParser {
    const BASE_URL = 'http://senat.gov.pl';

    /**
     * Send a POST requst using cURL
     * @param string $url to request
     * @param array $post values to send
     * @param array $options for cURL
     * @return string
     */
    private function curl_post($url, array $post = array(), array $options = array())
    {
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
        if( ! $result = curl_exec($ch))
        {
            throw new Exception('CURL error: '.curl_error($ch));
        }
        curl_close($ch);
        return $result;
    }

    /**
     * Send a GET requst using cURL
     * @param string $url to request
     * @param array $get values to send
     * @param array $options for cURL
     * @return string
     */
    private function curl_get($url, array $get = array(), array $options = array())
    {
        $defaults = array(
            CURLOPT_URL => $url. (strpos($url, '?') === FALSE ? '?' : ''). http_build_query($get),
            CURLOPT_HEADER => 0,
            CURLOPT_RETURNTRANSFER => TRUE,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (SenatParser; Fundacja Media 3.0) Gecko/20100101 Firefox/31.0',
            CURLOPT_TIMEOUT => 4
        );

        $ch = curl_init();
        curl_setopt_array($ch, ($options + $defaults));
        if( ! $result = curl_exec($ch))
        {
            throw new Exception('CURL error: '.curl_error($ch));
        }
        curl_close($ch);
        return $result;
    }

    /**
     * This will turn array 90 degrees. For example: [['a',1,true], ['b', 2, false]] will become [['a', 'b'], [1, 2], [true, false]]. Use it with matching.
     * @param array $m Input array
     * @return array $m Turned 90 degrees
     */
    private function turn_array($m)
    {
        for ($z = 0;$z < count($m);$z++)
        {
            for ($x = 0;$x < count($m[$z]);$x++)
            {
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
    private function trimEx($s, $chars = " \t\r\n"){
        return trim($s, $chars);
    }

    /**
     * Copy string from some character to some another character
     * @param string $s
     * @param string|int|null $from Set to NULL to copy from the beginning, set to some INTEGER value to copy from char-index.
     * @param string|int|null $to Set to NULL to copy till the end, set to some INTEGER value to copy characters count.
     * @return string $s[$from - $to]
     */
    private function cpyFromTo($s, $from = NULL, $to = NULL){
        if (!is_null($from)){
            if (is_int($from))
                $s = mb_substr($s, $from);
            else
                $s = mb_substr($s, mb_strpos($s, $from)+mb_strlen($from));
        }
        if (!is_null($to)){
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
    private function removeDblSpaces($s, $andTrimEx=TRUE){
        if ($andTrimEx)
            $s = $this->trimEx($s);
        $s = str_replace("\t", ' ', $s);
        while (strpos($s, '  ')!==false)
            $s = str_replace('  ', ' ', $s);
        return $s;
    }

    private function tryToFind1($subject, $regEx, $group, $default = ''){
        $matches = array();
        preg_match_all($regEx, $subject, $matches);
        if ((empty($matches))||(!is_array($matches[$group]))||(empty($matches[$group])))
            return $default;
        else
            return $matches[$group][0];
    }

    /**
     * This will extract OKW from senator`s info page html.
     * @param string $html
     * @return string Empty if not found.
     */
    private function _senatorExtractOKW($html){
        $re = "/<div class=\"informacje\">[\\s\\S]*?<li>(Okręg ([^<]*))<\\/li>/";

        $m = $this->tryToFind1($html, $re, 1);
        return $m;
    }

    /**
     * This will extract WWW from senator`s info page html.
     * @param string $html
     * @return string Empty if not found.
     */
    private function _senatorExtractWWW($html){
        $re = "/<div class=\"informacje\">[\\s\\S]*?<li>[\\s\\S]*?WWW:[^\"]*?\"([^\"]*)\"[\\s\\S]*?<\\/li>/";

        $m = $this->tryToFind1($html, $re, 1);
        return $m;
    }

    /**
     * This will extract cadencies from senator`s info page html.
     * @param string $html
     * @return array Empty if not found.
     */
    private function _senatorExtractCadencies($html){
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
    private function _senatorExtractEmail($html){
        $re = "/<div class=\"informacje\">[\\s\\S]*?<li>[\\s\\S]*?E-mail:[^<]*?<script type=\"text\\/javascript\">[^S]*SendTo\\('[^']*?', '[^']*?', '([^']*)', '([^']*)', [\\s\\S]*?<\\/script>[\\s\\S]*?<\\/li>/";

        $matches = array();
        preg_match_all($re, $html, $matches);
        if ((empty($matches))||(empty($matches[0])))
            return array();
        $matches = $this->turn_array($matches);

        if ((empty($matches))||(count($matches[0])<2))
            return '';
        else
            return $matches[0][1].'@'.$matches[0][2];
    }

    /**
     * This will extract clubs from senator`s info page html.
     * @param string $html
     * @return array Empty if not found.
     */
    private function _senatorExtractClubs($html){
        if (strpos($html, '<div class="kluby">')===false)
            return array();
        $html = $this->cpyFromTo($html, '<div class="kluby">', '</div>');

        $re = "/<p><a href=\"(([^#]*)#klub-([\\d]*))\" title=\"([^\"]*)\"[^<]*?<\\/a><\\/p>/";

        $matches = array();
        preg_match_all($re, $html, $matches);
        $matches = $this->turn_array($matches);

        if ((empty($matches))||(count($matches[0])<5))
            return array();

        $clubs = array();

        foreach($matches as $match){
            $clubs[$match[3]] = array(
                'id'=>$match[3],
                'name'=>$match[4],
                'url'=>self::BASE_URL.$match[1]
            );
        }

        return $clubs;
    }

    /**
     * This will extract Biographic Note from senator`s info page html.
     * @param string $html
     * @return string Empty if not found.
     */
    private function _senatorExtractBioNote($html){
        $re = "/<div class=\"sekcja-2\">[^<]*?<p style=\"text-align: justify;\">([\\s\\S]*)<\\/div>[^<]*?<div class=\"sekcja-2\">/";

        $m = $this->tryToFind1($html, $re, 1);

        $m = $this->removeDblSpaces(strip_tags($m));

        return $m;
    }

    /**
     * This will extract employees and cooperators from senator`s info page html.
     * @param string $html
     * @return array Empty if not found.
     */
    private function _senatorExtractEmployees($html){
        if (strpos($html, '<h3>Pracownicy i współpracownicy</h3>')===false)
            return array();
        $html = $this->cpyFromTo($html, '<h3>Pracownicy i współpracownicy</h3>', '<div class="js-content komisje">');

        $re = "/<div>[\\s\\S]*?<a href=\"([^\"]*)\">([^<]*)<\\/a>[^<]*?<\\/div>/";

        $matches = array();
        preg_match_all($re, $html, $matches);
        if ((empty($matches))||(empty($matches[0])))
            return array();

        $matches = $this->turn_array($matches);

        if ((empty($matches))||(count($matches[0])<3))
            return array();

        $employees = array();

        foreach($matches as $match){
            $employees[] = array(
                'name'=>$match[2],
                'url'=>self::BASE_URL.$match[1]
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
    private function __senatorExtractTeams($html, $from, $to){
        if (strpos($html, $from)===false)
            return array();
        $html = $this->cpyFromTo($html, $from, $to);

        $re = "/<li>[^<]*?<a href=\"([^,]*,([\\d]*),[^\"]*)\">([^<]*)<\\/a>([\\s\\S]*?)<p>([^<]*?)<\\/p>[\\s\\S]*?<\\/li>/";

        $matches = array();
        preg_match_all($re, $html, $matches);
        $matches = $this->turn_array($matches);

        if ((empty($matches))||(count($matches[0])<6))
            return array();

        $team = array();

        foreach($matches as $match){
            $team[$match[2]] = array(
                'id'=>$match[2],
                'name'=>$match[3],
                'url'=>self::BASE_URL.$match[1],
                'notes'=>$this->removeDblSpaces(strip_tags($match[4])),
                'when'=>$this->removeDblSpaces(strip_tags($match[5]))
            );
        }

        return $team;
    }

    /**
     * This will extract commissions from senator`s info page html.
     * @param string $html
     * @return array Empty if not found.
     */
    private function _senatorExtractCommissions($html){
        return $this->__senatorExtractTeams($html, '<div class="js-content komisje">', '</ul>');
    }

    /**
     * This will extract parliamentary assemblies from senator`s info page html.
     * @param string $html
     * @return array Empty if not found.
     */
    private function _senatorExtractParlimentaryAssemblies($html){
        return $this->__senatorExtractTeams($html, '<p class="etykieta">Zespoły parlamentarne</p>', '</ul>');
    }

    /**
     * This will extract parliamentary assemblies from senator`s info page html.
     * @param string $html
     * @return array Empty if not found.
     */
    private function _senatorExtractSenatAssemblies($html){
        return $this->__senatorExtractTeams($html, '<p class="etykieta">Zespoły senackie</p>', '</ul>');
    }

    public function updateSenatorSpeechesList($SenatorID){
        $html = $this->curl_get(self::BASE_URL.'/sklad/senatorowie/aktywnosc,'.$SenatorID.',8.html');

        $re = "/<tr>[^<]*?<td class=\"numer-posiedzenia\">([\\d]*?)<\\/td>[\\s\\S]*?<td class=\"data-aktywnosci nowrap\">([^<]*?)<\\/td>[^<]*?<td class=\"punkt\">([^<]*?)<\\/td>[^<]*?<td class=\"etapy\">([\\s\\S]*?)<\\/td>[^<]*?<\\/tr>/";

        $reAct = "/<p>[^<]*?<a[\\s\\S]*?href=\"\\/prace\\/senat\\/posiedzenia\\/przebieg,([^,]*),[\\d]*([^.]*?)\\.html#([^\"]*)\"[^>]*?>([^<]*)<\\/a>[^<]*?<\\/p>/";

        $matches = array();
        preg_match_all($re, $html, $matches);
        $matches = $this->turn_array($matches);

        $ar = array();
        foreach($matches as $match){
            $activity = array();

            $actMatches = array();
            preg_match_all($reAct, $match[4], $actMatches);
            $actMatches = $this->turn_array($actMatches);
            foreach($actMatches as $aMatch){
                $activity[] = array(
                    'title'=>$this->trimEx($aMatch[4]),
                    'is_in_stenogram'=>$aMatch[2]=='',
                    'meeting_id'=>$aMatch[1],
                    'speech_ref'=>$aMatch[3]
                );
            }

            $ar[] = array(
                'no_of_meeting'=>$match[1],
                'when'=>$this->trimEx($match[2]),
                'title_of_agenda_item'=>$this->trimEx($match[3]),
                'activity'=>$activity
            );
        }

        return $ar;
    }

    public function updateSenatorVotesAtMeeting($SenatorID, $MeetingID){
        $html = $this->curl_get(self::BASE_URL.'/sklad/senatorowie/aktywnosc-glosowania,'.$SenatorID.',8,szczegoly,'.$MeetingID.'.html');

        $re = "/<td>[^<]*?<a href=\"(\\/sklad\\/senatorowie\\/szczegoly-glosowania,([^,]*),([^,]*),8.html)\">[^<]*?<\\/a>[^<]*?<\\/td>[^<]*?<td>[^<]*?<\\/td>[^<]*?<td>([^<]*)<\\/td>/";

        $matches = array();
        preg_match_all($re, $html, $matches);
        $matches = $this->turn_array($matches);

        $ans = array();
        foreach($matches as $match){
            $ans[$match[2].','.$match[3]] = array(
                'voting_id'=>$match[2].','.$match[3],
                'vote'=>$this->trimEx($match[4])
            );
        }
        return $ans;
    }

    //Vote might me: za/przeciw/wstrzymał się/nie głosował/nieobecny
    public function updateSenatorVotingActivity($SenatorID){
        $html = $this->curl_get(self::BASE_URL.'/sklad/senatorowie/aktywnosc-glosowania,'.$SenatorID.',8.html');

        $re = "/<td class=\"nowrap\">[^<]*?<a href=\"(\\/sklad\\/senatorowie\\/aktywnosc-glosowania,[\\d]*,8,szczegoly,([^.]*)\\.html)\">[^<]*?<\\/a>[^<]*?<\\/td>/";

        $matches = array();
        preg_match_all($re, $html, $matches);
        $matches = $this->turn_array($matches);

        $ans = array();
        foreach($matches as $match){
            $ans[$match[2]] = $this->updateSenatorVotesAtMeeting($SenatorID, $match[2]);
        }
        return $ans;
    }

    public function updateSenatorInfo($SenatorID){
        try{
        $html = $this->curl_get(self::BASE_URL.'/sklad/senatorowie/senator,'.$SenatorID.',8,get-data.html');
        } catch (Exception $e){
            return false;
        }
        $answer = array(
            'okw'=>$this->_senatorExtractOKW($html),
            'cadencies'=>$this->_senatorExtractCadencies($html),
            'email'=>$this->_senatorExtractEmail($html),
            'www'=>$this->_senatorExtractWWW($html),
            'clubs'=>$this->_senatorExtractClubs($html),
            'bio_note'=>$this->_senatorExtractBioNote($html),
            'statements_of_assets_and_record_of_benefits'=>self::BASE_URL.'/sklad/senatorowie/oswiadczenia,'.$SenatorID.',8.html',
            'employees_cooperates'=>$this->_senatorExtractEmployees($html),
            'commissions'=>$this->_senatorExtractCommissions($html),
            'parliamentary_assemblies'=>$this->_senatorExtractParlimentaryAssemblies($html),
            'senat_assemblies'=>$this->_senatorExtractSenatAssemblies($html),
            'activity_senat_meetings'=>$this->updateSenatorSpeechesList($SenatorID),
            'senator_statements'=>self::BASE_URL.'/sklad/senatorowie/oswiadczenia-senatorskie,'.$SenatorID.',8.html',
        );
        $answer['checksum'] = $this->getChecksumForInfo($answer);
        return $answer;
    }

    public function updateSenatorsList(){
        $html = $this->curl_get(self::BASE_URL.'/sklad/senatorowie/');

        $re = "/<div class=\"senator-kontener\"[^<]*<div class=\"zdjecie\">[^<]*?<img src=\"([^\"]*?)\"[^<]*?<\\/div>[\\s\\S]*?<a href=\"\\/sklad\\/senatorowie\\/senator,([^\"]*)\">([^<]*)<\\/a>[\\s\\S]*?(<p class=\"adnotacja\">([^<]*)<\\/p>| )/"; //

        $base_info_matches = array();
        preg_match_all($re, $html, $base_info_matches);
        $base_info_matches = $this->turn_array($base_info_matches);

        $answer = array();

        foreach($base_info_matches as $info){
            $id = $this->cpyFromTo($info[2], NULL, ',');
            $answer[$id] = array(
                'id'=>$id,
                'name'=>$info[3],
                'notes'=>$this->removeDblSpaces($info[5]),
                'photo'=>self::BASE_URL.$info[1],
                'url'=>self::BASE_URL.'/sklad/senatorowie/senator,'.$info[2]
            );
        }
        return $answer;
    }

    /**
     * This will create md5 hash check-sum for input array, to save its current state
     * @param $inArray Original input array
     * @return string MD5 check-sum.
     */
    public function getChecksumForInfo($inArray){
        return md5(json_encode($inArray));
    }

    public function updateSenatorsAll(){
        $list = $this->updateSenatorsList();

        foreach($list as &$senator){
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
    public function extractSpeechRelFromStenogram($meetingTxt, $speechRel, $removeMarszalekEtc=true){
        $meetingTxt = $this->cpyFromTo($meetingTxt, '<h3 class="speech-rel" id="'.$speechRel.'" rel="'.$speechRel.'"></h3>');
        if (strpos($meetingTxt, '<h3 class="speech-rel"')!==FALSE)
            $meetingTxt = $this->cpyFromTo($meetingTxt, null, '<h3 class="speech-rel"');

        if ($removeMarszalekEtc){
            if (strpos($meetingTxt, ".\r\nMarszałek ")!==FALSE)
                $meetingTxt = $this->cpyFromTo($meetingTxt, null, ".\r\nMarszałek ").'.';
            if (strpos($meetingTxt, ".\r\nWicemarszałek ")!==FALSE)
                $meetingTxt = $this->cpyFromTo($meetingTxt, null, ".\r\nWicemarszałek ").'.';
            if (strpos($meetingTxt, ".\r\n(Przewodnictwo ")!==FALSE)
                $meetingTxt = $this->cpyFromTo($meetingTxt, null, ".\r\n(Przewodnictwo ").'.';
        }

        $meetingTxt = $this->trimEx($meetingTxt);

        return $meetingTxt;
    }

    /**
     * This will get full stenogram text from meeting.
     * IMPORTANT NOTE: Stenogram will include only one HTML tag: '<h3 class="speech-rel" id="$SPEECH_REL" rel="$SPEECH_REL"></h3>' - everywhere where Senator took a voice, use it to navigate through speeches. DO NOT remove it from database in case to use ::extractSpeechRelFromStenogram.
     * @param string $meetingID Meeting ID.
     * @return string Text, witouh double spaces, HTML etc.
     */
    public function updateMeetingStenogram($meetingID){
        $text = "\r\n";

        $i=0;
        do {
            $i++;
            $html = $this->curl_get(self::BASE_URL.'/prace/senat/posiedzenia/przebieg,'.$meetingID.','.$i.'.html');

            $re = "/<div id=\"jq-stenogram-tresc\">([\\s\\S]*?)<script type=\"text\\/javascript\" src=\"\\/szablony\\/senat\\/scripts\\/jquery.colorbox-min.js\"><\\/script>/";

            $tmp=$this->tryToFind1($html, $re, 1);
            if (!empty($tmp)){
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
                $text.=$tmp;
            }

        } while(strpos($html, ' class="link-stenogram-nastepny-dzien"')!==FALSE);

        return $this->trimEx($text);
    }

    public function updateMeetingVotings($meetingID){
        $ans = array();

        $i=0;
        do {
            $i++;
            $html = $this->curl_get(self::BASE_URL.'/prace/senat/posiedzenia/przebieg,'.$meetingID.','.$i.',glosowania.html');

            $re = "/<tr>[^<]*?<td>[^<]*?<\\/td>[^<]*?<td>([\\s\\S]*?)<\\/td>[\\s\\S]*?<a href=\"http:\\/\\/senat.gov.pl\\/sklad\\/senatorowie\\/szczegoly-glosowania,([^,]*,[^,]),8.html\">/";

            $matches = array();
            preg_match_all($re, $html, $matches);
            $matches = $this->turn_array($matches);

            foreach($matches as $match){
                $ans[$match[2]] = array(
                    'voting_id'=>$match[2],
                    'day'=>$i,
                    'subject'=>$this->removeDblSpaces(strip_tags($match[1]))
                );
            }

        } while(strpos($html, ' class="link-stenogram-nastepny-dzien"')!==FALSE);

        return $ans;
    }

    /**
     * This will return list of all meetings
     * @return array ['id'=>ID of meeting, 'name'=>Name/title/subject of meeting. 'when'=>Date(s) of meeting(s)]
     */
    public function updateMeetingsList(){
        $answer = array();

        $i=0;
        do {
            $i++;
            $html = $this->curl_get(self::BASE_URL.'/prace/senat/posiedzenia/page,'.$i.'.html');

            $re = "/<tr [^<]*?>[^<]*?<td class=\"pierwsza\">[\\s\\S]*?<a href=\"(([^,]*),([\\d]*?),([^\"]*))\"[^>]*?>([^<]*)<\\/a>[\\s\\S]*?<\\/td>[\\s\\S]*?<td>([^<]*)<\\/td>[\\s\\S]*?<td class=\"ostatnia\">[\\s\\S]*?<a class=\"stenogram-link\".*?href=\"([^\"]*)\"[^>]*?>[\\s\\S]*?<\\/tr>/";
            $base_info_matches = array();
            preg_match_all($re, $html, $base_info_matches);
            $base_info_matches = $this->turn_array($base_info_matches);

            foreach($base_info_matches as $info){
                $id = $info[3];
                $answer[$id] = array(
                    'id'=>$id,
                    'name'=>$this->trimEx($info[5]),
                    'when'=>$this->trimEx($info[6]),
//                    'topics_url'=>self::BASE_URL.$info[1],
//                    'stenogram_url'=>self::BASE_URL.$info[7]
                );
            }

        } while(strpos($html, '<div class="pager-nastepne">')!==FALSE);
        return $answer;
    }
} 