<?php

namespace parldata\names;

class Polish {
    static public $dictionary = array(
        'Eliza' => 'female',
        'Łukasz' => 'male',
        'Czesław' => 'male',
        'Artur' => 'male',
        'Marcin' => 'male',
        'Czesława' => 'female',
        'Mateusz' => 'male',
        'Dariusz' => 'male',
        'Włodzisław' => 'male',
        'Bartosz' => 'male',
        'Bartłomiej' => 'male',
        'Irena' => 'female',
        'Olgierd' => 'male',
        'Tomasz' => 'male',
        'Urszula' => 'female',
        'Lech' => 'male',
        'Ewa' => 'female',
        'Mariusz' => 'male',
        'Agnieszka' => 'female',
        'Iwona' => 'female',
        'Krystyna' => 'female',
        'Monika' => 'female',
        'Katarzyna' => 'female',
        'Edyta' => 'female',
        'Longin' => 'male',
        'Hanna' => 'female',
        'Aneta' => 'female',
        'Igor' => 'male',
        'Zofia' => 'female',
        'Marceli' => 'male',
        'Patrycja' => 'female',
        'Mirosław' => 'male',
        'Jakub' => 'male',
        'Radosław' => 'male',
        'Daria' => 'female',
        'Magdalena' => 'female',
        'Małgorzata' => 'female',
        'Bronisław' => 'female',
        'Jacek' => 'male',
        'Joanna' => 'female',
        'Paweł' => 'male',
        'Cezary' => 'male',
        'Anna' => 'female',
        'Tadeusz' => 'male',
        'Mieczysław' => 'male',
        'Elżbieta' => 'female',
        'Grzegorz' => 'male',
        'Przemysław' => 'male',
        'Ryszard' => 'male',
        'Marek' => 'male',
        'Bogdan' => 'male',
        'Barbara' => 'female',
        'Jerzy' => 'male',
        'Alicja' => 'female',
        'Włodzimierz' => 'male',
        'Lena' => 'female',
        'Mikołaj' => 'male',
        'Henryk' => 'male',
        'Leszek' => 'male',
        'Dorota' => 'female',
        'Wiesław' => 'male',
        'Robert' => 'male',
        'Jarosław' => 'male',
        'Witold' => 'male',
        'Stanisław' => 'male',
        'Beata' => 'female',
        'Maciej' => 'male',
        'Piotr' => 'male',
        'Andrzej' => 'male',
        'Helena' => 'female',
        'Jan' => 'male',
        'Kazimierz' => 'male',
        'Izabela' => 'female',
        'Maria' => 'female',
        'Waldemar' => 'male',
        'Zbigniew' => 'male',
        'Antoni' => 'female',
        'Andżelika' => 'female',
        'Rafał' => 'male',
        'Ireneusz' => 'male',
        'Norbert' => 'male',
        'Władysław' => 'male',
        'Bohdan' => 'male',
        'Bolesław' => 'male',
        'Józef' => 'male',
        'Aleksander' => 'male',
        'Marian' => 'male',
        'Sławomir' => 'male',
        'Zdzisław' => 'male',
        'Jadwiga' => 'female',
        'Janina' => 'female',
        'Janusz' => 'male',
        'Michał' => 'male',
        'Wojciech' => 'male',
        'Krzysztof' => 'male',
        'Grażyna' => 'female',
        'Bogusław' => 'male',
        'Edmund' => 'male',
        'Roman' => 'male',
        'Adam' => 'male',

        // not polish but needed
        'János' => 'male'
    );

    public $guesses = array();

    /**
     * @param $given_name
     * @return 'male' or 'female' or null if unknown
     */
    static function getGender($given_name) {
        if (isset(self::$dictionary[$given_name])) {
            return self::$dictionary[$given_name];
        }

        return null;
    }

    /**
     * Returns best guess based on given_name
     *
     * @param $given_name
     * @return string
     */
    static function guessGender($given_name) {
        if (in_array(
            $given_name[strlen($given_name)-1],
            array('a','e','o','u','i'))) {
            return 'female';
        }
        return 'male';
    }

    function mapGender($given_name) {
        $given_name = trim($given_name);

        if (has_key(self::$dictionary, $given_name)) {
            return self::$dictionary[$given_name];
        }
        if (has_key($this->guesses, $given_name)) {
            return $this->guesses[$given_name];
        }

        return $this->guesses[$given_name] = self::guessGender($given_name);
    }
}
