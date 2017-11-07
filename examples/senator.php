<!DOCTYPE html>
<html>
<head>
    <title>Senat Parser. (c) 2014/2015 Jakub Król, Fundacja Media 3.0</title>
    <meta charset="utf8" />
</head>
<body style="text-align:center">

JSON including all the info, please view it for example at http://jsonviewer.stack.hu/ or http://json.parser.online.fr/ or https://www.jsoneditoronline.org/ <br><br>
<?php

    //Senat Parser must be included and created
    include_once('lib/SenatParser.class.php');
    $SP = new SenatParser();

    //IMPORTANT! Enable it if script execution takes too much time
    set_time_limit(999999);

    //Force updating all info about Senators and dump info:
    $data = $SP->updateSenatorVotingActivity(33);

    echo '<textarea>'.json_encode($data).'</textarea>';

?>

<br><br><em>Senat Parser. &copy; 2014/2015 Jakub Król, Fundacja Media 3.0 ( http://media30.pl )</em>
</body>
</html>