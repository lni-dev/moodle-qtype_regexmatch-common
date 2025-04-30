<?php declare(strict_types=1);

const SEPARATOR_KEY = 'separator=';
const FEEDBACK_KEY = 'feedback=';
const SIZE_KEY = "size=";
const POINTS_KEY = 'points=';
const COMMENT_KEY = 'comment=';

class regex {
    /** @var mixed Whether to use the ignore case modifier (0 = false, 1 = true). */
    public $ignorecase;
    /** @var mixed Whether to use the dot all modifier (0 = false, 1 = true). */
    public $dotall;
    /** @var mixed Whether to replcase all spaces with [ \t]+ (0 = false, 1 = true). */
    public $infspace;
    /** @var mixed trim leading and trailing spaces in the answer (0 = false, 1 = true). */
    public $trimspaces;
    /** @var mixed allow infinite trailing and leading spaces around pipes and semicolons (0 = false, 1 = true). */
    public $pipesemispace;
    /** @var mixed Allows infnite trailing and leading spaces around input/output redirections (0 = false, 1 = true). */
    public $redictspace;
    /**
     * @var boolean matches multiple regexes in any order
     */
    public $matchAnyOrder;

    /**
     * @var int the rating percentage.
     */
    public $percent;
    /**
     * @var array<string> The actual regex without any options.
     */
    public $regexes;

    public function __construct($percent, $regularExpressions, $options) {
        $this->ignorecase = false;
        $this->dotall = false;
        $this->pipesemispace = false;
        $this->redictspace = false;
        $this->matchAnyOrder = false;

        // On by default
        $this->infspace = true;
        $this->trimspaces = true;
        $this->percent = $percent;

        // Now split the regexes into an array
        $this->regexes = preg_split("/]][ \\n]*\[\[/", $regularExpressions);

        // Next read the different options
        $this->readOptions($options);
    }

    /**
     * @param string $options without leading or trailing "/"
     * @return void
     */
    private function readOptions($options) {
        foreach (str_split($options) as $option) {
            switch ($option) {
                // Capital letter enables the option, lower case letter disables the option.

                case 'I': $this->ignorecase = true; break;
                case 'D': $this->dotall = true; break;
                case 'P': $this->pipesemispace = true; break;
                case 'R': $this->redictspace = true; break;
                case 'O': $this->matchAnyOrder = true; break;
                case 'S': $this->infspace = true; break;
                case 'T': $this->trimspaces = true; break;

                case 'i': $this->ignorecase = false; break;
                case 'd': $this->dotall = false; break;
                case 'p': $this->pipesemispace = false; break;
                case 'r': $this->redictspace = false; break;
                case 'o': $this->matchAnyOrder = false; break;
                case 's': $this->infspace = false; break;
                case 't': $this->trimspaces = false; break;
            }
        }
    }
}

class qtype_regexmatch_answer extends question_answer {

    /**
     * @var array<regex|null>
     */
    public $regexes;

    /**
     * @var string Separator used by the match any order (O) option
     */
    public $separator = "\n";

    /**
     * @var int points. Only used by the cloze regex plugin.
     */
    public $points = 1;

    /**
     * @var int size of the input field. Only used by the cloze regex plugin.
     */
    public $size = 5;

    /**
     * @var string feedback specified by the FEEDBACK_KEY.
     */
    public $feedbackValue = "";

    public function __construct($id, $answer, $fraction, $feedback, $feedbackformat) {
        parent::__construct($id, $answer, $fraction, $feedback, $feedbackformat);
        $this->parse($answer);
    }

    private function parse($unparsed) {

        // Remove all \r
        $remaining = preg_replace("/\\r/", "", $unparsed);

        // First look for the options "]] /OPTIONS/"
        if(preg_match("%]][ \\n]*/[a-zA-Z]*/%", $remaining, $matches, PREG_OFFSET_CAPTURE)) {
            $first = true;
            do {
                if($first) {
                    $first = false;
                    $percent = 100;
                    $percentOffset = 0;

                } else {

                    if(!preg_match("%]][ \\n]*/[a-zA-Z]*/%", $remaining, $matches, PREG_OFFSET_CAPTURE)) {
                        //Invalid syntax.
                        $this->regexes = array(null);
                        return;
                    }

                    preg_match("/%[0-9]+/", $remaining, $percentMatch);
                    $percent = substr($percentMatch[0], 1);
                    $percentOffset = strlen($percentMatch[0]);
                }

                $index = intval($matches[0][1]);

                // Regexes without the last "]]". E.g.: [[regex1]] [[regex2
                $regularExpressions = substr($remaining, $percentOffset, $index);
                $regularExpressions = trim($regularExpressions); // Now trim all spaces at the beginning and end
                $regularExpressions = substr($regularExpressions, 2); // remove the starting "[["

                // Options E.g.: "OPTIONS"
                $options = substr($matches[0][0], 2); // first remove the "]]" at the beginning
                $options = trim($options); // Now trim all spaces at the beginning and end
                $options = substr($options, 1, strlen($options) - 2); // remove first and last "/"

                $this->regexes[] = new regex($percent, $regularExpressions, $options);

                // Key Value pairs or more regexes (cloze)
                $remaining = substr($remaining, $index + strlen($matches[0][0]));
                $remaining = trim($remaining);

            } while(my_str_starts_with($remaining, "%"));

            // At last read the key value pairs
            $this->readKeyValuePairs($remaining);

        } else {
            //Invalid syntax. Maybe it is an old regex
            $this->regexes = array(null);
        }
    }

    private function readKeyValuePairs($keyValuePairs) {
        $lines = preg_split("/\\n/", $keyValuePairs);
        $current = -1; // For multi line values
        foreach ($lines as $line) {
            if(my_str_starts_with($line, COMMENT_KEY)) {
                $current = 0;
                //This can safely be ignored

            } else if (my_str_starts_with($line, SEPARATOR_KEY)) {
                $current = -1; // separator can only be a single line
                $this->separator = substr($line, strlen(SEPARATOR_KEY));

            } else if (my_str_starts_with($line, FEEDBACK_KEY)) {
                $current = 1;
                $this->feedbackValue = substr($line, strlen(FEEDBACK_KEY));

            } else if (my_str_starts_with($line, POINTS_KEY)) {
                $current = -1; // points can only be a single line
                $this->points = intval(substr($line, strlen(POINTS_KEY)));

            } else if (my_str_starts_with($line, SIZE_KEY)) {
                $current = -1; // size can only be a single line
                $this->size = intval(substr($line, strlen(SIZE_KEY)));

            } else {
                if($current === 0) continue;
                if($current === 1) $this->feedback .= $line;
            }
        }
    }
}

/**
 * @param string $haysack
 * @param string $needle
 * @return bool true of haysack starts with needle.
 */
function my_str_starts_with($haysack, $needle) {
    return substr($haysack, 0, strlen($needle)) === $needle;
}

function construct_regex(string $regex, regex $options): string {
    $constructedRegex = $regex;

    if($options->infspace)
        $constructedRegex = str_replace(" ", "(?:[ \t]+)", $constructedRegex);

    if($options->pipesemispace)
        $constructedRegex = str_replace(
            array(";", "\|"),
            array("(?:[ \t]*[;\\n][ \t]*)", "(?:[ \t]*\|[ \t]*)"),
            $constructedRegex
        );

    if($options->redictspace)
        $constructedRegex = str_replace(
            array("<", "<<", ">", ">>"),
            array("(?:[ \t]*<[ \t]*)", "(?:[ \t]*<<[ \t]*)", "(?:[ \t]*>[ \t]*)", "(?:[ \t]*>>[ \t]*)"),
            $constructedRegex
        );

    // preg_match requires a delimiter ( we use "/").
    // replace all actual occurrences of "/" in $regex->answer with an escaped version ("//").
    // Add "^(?:" at the start of the regex and ")$" at the end, to match from start to end.
    // and put the regex in a non-capturing-group, so the function of the regex does not change (eg. "^a|b$" vs "^(?:a|b)$")
    $toEscape = array("/");
    $escapeValue = array("\\/");
    $constructedRegex = "/^(?:" . str_replace($toEscape, $escapeValue, $constructedRegex) . ")$/";

    // Set Flags based on enabled options
    if($options->ignorecase)
        $constructedRegex .= "i";

    if($options->dotall)
        $constructedRegex .= "s";

    return $constructedRegex;
}

/**
 * @param qtype_regexmatch_answer $answer
 * @param regex $regex
 * @param string $submittedAnswer
 * @return float How correct the answer is for this regex is between 0.0 (wrong) and 1.0 (correct).
 */
function try_regex(qtype_regexmatch_answer $answer, regex $regex, string $submittedAnswer) {
    $processedAnswer = $submittedAnswer;

    // Trim answer if enabled.
    if( $regex->trimspaces)
        $processedAnswer = trim($processedAnswer);

    if( $regex->matchAnyOrder) {
        $answerLines = explode($answer->separator, $processedAnswer);
        $answerLineCount = count($answerLines);

        // Trim all answers if enabled.
        if( $regex->trimspaces) {
            for ($i = 0; $i < $answerLineCount; $i++) {
                $answerLines[$i] = trim($answerLines[$i]);
            }
        }

        foreach ($regex->regexes as $r) {
            $r = construct_regex($r,  $regex);

            $i = 0;
            for (; $i < $answerLineCount; $i++) {
                if($answerLines[$i] === null)
                    continue;
                if(preg_match($r, $answerLines[$i]) == 1) {
                    break;
                }
            }

            if($i !== $answerLineCount) {
                $answerLines[$i] = null;
            }
        }

        $wrongAnswerCount = 0;
        foreach ($answerLines as $answerLine) {
            if($answerLine !== null) $wrongAnswerCount++;
        }

        $maxPoints = count($regex->regexes);
        $answerCountDif = $maxPoints - $answerLineCount;
        $points = max(0, $maxPoints - abs($answerCountDif) - ($wrongAnswerCount - max(0, -$answerCountDif)));

        return (floatval($points) / floatval($maxPoints));
    }

    // Construct regex based on enabled options
    $constructedRegex = construct_regex($regex->regexes[0],  $regex);

    if(preg_match($constructedRegex, $processedAnswer) == 1) {
        return 1.0;
    }

    return 0.0;
}