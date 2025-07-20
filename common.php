<?php declare(strict_types=1);

// This file contains common classes and constants for regexmatch and regexmatchcloze.

const QTYPE_REGEXMATCH_SEPARATOR_KEY = 'separator=';
const QTYPE_REGEXMATCH_FEEDBACK_KEY = 'feedback=';
const QTYPE_REGEXMATCH_SIZE_KEY = "size=";
const QTYPE_REGEXMATCH_POINTS_KEY = 'points=';
const QTYPE_REGEXMATCH_COMMENT_KEY = 'comment=';

/**
 * Class representing a single possible solution called a regex.
 *
 * Usually a single regular expression (if match any order is disabled).
 * @copyright  2024 Linus Andera (linus@linusdev.de)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class qtype_regexmatch_common_regex {
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

    public function __construct($percent, $regularexpressions, $options) {
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
        $this->regexes = preg_split("/]][ \\n]*\[\[/", $regularexpressions);

        // Next read the different options
        $this->readOptions($options);
    }

    /**
     * Read the options of this regex.
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

/**
 * question_answer class for regexmatch and regexmatchcloze
 */
class qtype_regexmatch_common_answer extends question_answer {

    /**
     * @var array<qtype_regexmatch_common_regex|null>
     */
    public $regexes;

    /**
     * @var string Separator used by the match any order (O) option
     */
    public $separator = "\n";

    /**
     * @var float points. Only used by the cloze regex plugin.
     */
    public $points = 1.0;

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

    /**
     * Parses the regex inputted from the user when creating/editing a question.
     * @param $unparsed string raw string from user input
     * @return void
     */
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
                    $percentoffset = 0;

                } else {

                    if(!preg_match("%]][ \\n]*/[a-zA-Z]*/%", $remaining, $matches, PREG_OFFSET_CAPTURE)) {
                        //Invalid syntax.
                        $this->regexes = array(null);
                        return;
                    }

                    preg_match("/%[0-9]+/", $remaining, $percentMatch);
                    $percent = substr($percentMatch[0], 1);
                    $percentoffset = strlen($percentMatch[0]);
                }

                $index = intval($matches[0][1]);

                // Regexes without the last "]]". E.g.: [[regex1]] [[regex2
                $regularexpressions = substr($remaining, $percentoffset, $index - $percentoffset);
                $regularexpressions = trim($regularexpressions); // Now trim all spaces at the beginning and end
                $regularexpressions = substr($regularexpressions, 2); // remove the starting "[["

                // Options E.g.: "OPTIONS"
                $options = substr($matches[0][0], 2); // first remove the "]]" at the beginning
                $options = trim($options); // Now trim all spaces at the beginning and end
                $options = substr($options, 1, strlen($options) - 2); // remove first and last "/"

                $this->regexes[] = new qtype_regexmatch_common_regex($percent, $regularexpressions, $options);

                // Key Value pairs or more regexes (cloze)
                $remaining = substr($remaining, $index + strlen($matches[0][0]));
                $remaining = trim($remaining);

            } while(qtype_regexmatch_common_str_starts_with($remaining, "%"));

            // At last read the key value pairs
            $this->readKeyValuePairs($remaining);

        } else {
            //Invalid syntax. Maybe it is an old regex
            $this->regexes = array(null);
        }
    }

    /**
     * Parses key value pairs.
     * @param $keyvaluepairs
     * @return void
     */
    private function readKeyValuePairs($keyvaluepairs) {
        $lines = preg_split("/\\n/", $keyvaluepairs);
        $current = -1; // For multi line values
        foreach ($lines as $line) {
            if(qtype_regexmatch_common_str_starts_with($line, QTYPE_REGEXMATCH_COMMENT_KEY)) {
                $current = 0;
                //This can safely be ignored

            } else if (qtype_regexmatch_common_str_starts_with($line, QTYPE_REGEXMATCH_SEPARATOR_KEY)) {
                $current = -1; // separator can only be a single line
                $this->separator = substr($line, strlen(QTYPE_REGEXMATCH_SEPARATOR_KEY));

            } else if (qtype_regexmatch_common_str_starts_with($line, QTYPE_REGEXMATCH_FEEDBACK_KEY)) {
                $current = 1;
                $this->feedbackValue = substr($line, strlen(QTYPE_REGEXMATCH_FEEDBACK_KEY));

            } else if (qtype_regexmatch_common_str_starts_with($line, QTYPE_REGEXMATCH_POINTS_KEY)) {
                $current = -1; // points can only be a single line
                $this->points = floatval(trim(substr($line, strlen(QTYPE_REGEXMATCH_POINTS_KEY))));

            } else if (qtype_regexmatch_common_str_starts_with($line, QTYPE_REGEXMATCH_SIZE_KEY)) {
                $current = -1; // size can only be a single line
                $this->size = intval(substr($line, strlen(QTYPE_REGEXMATCH_SIZE_KEY)));

            } else {
                if($current === 0) continue;
                if($current === 1) $this->feedback .= $line;
            }
        }
    }
}

/**
 * Checks if string starts with needle.
 * @param string $haysack
 * @param string $needle
 * @return bool true of haysack starts with needle.
 */
function qtype_regexmatch_common_str_starts_with($haysack, $needle) {
    return substr($haysack, 0, strlen($needle)) === $needle;
}

/**
 * Constructs a regular expression that can be used in the PCRE-functions, based on given options.
 * @param string $regex
 * @param qtype_regexmatch_common_regex $options
 * @return string
 */
function qtype_regexmatch_common_construct_regex(string $regex, qtype_regexmatch_common_regex $options): string {
    $constructedregex = $regex;

    if($options->infspace)
        $constructedregex = str_replace(" ", "(?:[ \t]+)", $constructedregex);

    if($options->pipesemispace)
        $constructedregex = str_replace(
            array(";", "\|"),
            array("(?:[ \t]*[;\\n][ \t]*)", "(?:[ \t]*\|[ \t]*)"),
            $constructedregex
        );

    if($options->redictspace)
        $constructedregex = str_replace(
            array("<", "<<", ">", ">>"),
            array("(?:[ \t]*<[ \t]*)", "(?:[ \t]*<<[ \t]*)", "(?:[ \t]*>[ \t]*)", "(?:[ \t]*>>[ \t]*)"),
            $constructedregex
        );

    // preg_match requires a delimiter ( we use "/").
    // replace all actual occurrences of "/" in $regex->answer with an escaped version ("//").
    // Add "^(?:" at the start of the regex and ")$" at the end, to match from start to end.
    // and put the regex in a non-capturing-group, so the function of the regex does not change (eg. "^a|b$" vs "^(?:a|b)$")
    $toescape = array("/");
    $escapevalue = array("\\/");
    $constructedregex = "/^(?:" . str_replace($toescape, $escapevalue, $constructedregex) . ")$/";

    // Set Flags based on enabled options
    if($options->ignorecase)
        $constructedregex .= "i";

    if($options->dotall)
        $constructedregex .= "s";

    return $constructedregex;
}

/**
 * Tests if given submitted_answer matches to given regex.
 * @param qtype_regexmatch_common_answer $answer
 * @param qtype_regexmatch_common_regex $regex
 * @param string $submittedanswer
 * @return float How correct the answer is for this regex is between 0.0 (wrong) and 1.0 (correct).
 */
function qtype_regexmatch_common_try_regex(qtype_regexmatch_common_answer $answer, qtype_regexmatch_common_regex $regex, string $submittedanswer) {
    $processedanswer = $submittedanswer;

    // Trim answer if enabled.
    if( $regex->trimspaces)
        $processedanswer = trim($processedanswer);

    if( $regex->matchAnyOrder) {
        $answerlines = explode($answer->separator, $processedanswer);
        $answerlinecount = count($answerlines);

        // Trim all answers if enabled.
        if( $regex->trimspaces) {
            for ($i = 0; $i < $answerlinecount; $i++) {
                $answerlines[$i] = trim($answerlines[$i]);
            }
        }

        foreach ($regex->regexes as $r) {
            $r = qtype_regexmatch_common_construct_regex($r,  $regex);

            $i = 0;
            for (; $i < $answerlinecount; $i++) {
                if($answerlines[$i] === null)
                    continue;
                if(preg_match($r, $answerlines[$i]) == 1) {
                    break;
                }
            }

            if($i !== $answerlinecount) {
                $answerlines[$i] = null;
            }
        }

        $wronganswercount = 0;
        foreach ($answerlines as $answerline) {
            if($answerline !== null) $wronganswercount++;
        }

        $maxpoints = count($regex->regexes);
        $answercountdif = $maxpoints - $answerlinecount;
        $points = max(0, $maxpoints - abs($answercountdif) - ($wronganswercount - max(0, -$answercountdif)));

        return (floatval($points) / floatval($maxpoints));
    }

    // Construct regex based on enabled options
    $constructedregex = qtype_regexmatch_common_construct_regex($regex->regexes[0],  $regex);

    if(preg_match($constructedregex, $processedanswer) == 1) {
        return 1.0;
    }

    return 0.0;
}