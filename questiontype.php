<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * The questiontype class for the multiple choice question type.
 *
 * @package    qtype_ordering
 * @copyright  2013 Gordon Bateson (gordon.bateson@gmail.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * The ordering question type.
 *
 * @copyright  2013 Gordon Bateson (gordon.bateson@gmail.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class qtype_ordering extends question_type {

    /** combined feedback fields */
    public $feedbackfields = array('correctfeedback', 'partiallycorrectfeedback', 'incorrectfeedback');

    /**
     * Utility method used by {@link qtype_renderer::head_code()}
     * It looks for any of the files script.js or script.php that
     * exist in the plugin folder and ensures they get included.
     * It also includes the jquery files required for this plugin
     */
    public function find_standard_scripts() {
        global $CFG, $PAGE;

        // Include "script.js" and/or "script.php" in the normal way.
        parent::find_standard_scripts();

        $version = '';
        $minversion = '1.11.0'; // Moodle 2.7.
        $search = '/jquery-([0-9.]+)(\.min)?\.js$/';

        // Make sure jQuery version is high enough
        // (required if Quiz is in a popup window)
        // Moodle 2.5 has jQuery 1.9.1.
        // Moodle 2.6 has jQuery 1.10.2.
        // Moodle 2.7 has jQuery 1.11.0.
        // Moodle 2.8 has jQuery 1.11.1.
        // Moodle 2.9 has jQuery 1.11.1.
        if (method_exists($PAGE->requires, 'jquery')) {
            // Moodle >= 2.5.
            if ($version == '') {
                include($CFG->dirroot.'/lib/jquery/plugins.php');
                if (isset($plugins['jquery']['files'][0])) {
                    if (preg_match($search, $plugins['jquery']['files'][0], $matches)) {
                        $version = $matches[1];
                    }
                }
            }
            if ($version == '') {
                $filename = $CFG->dirroot.'/lib/jquery/jquery*.js';
                foreach (glob($filename) as $filename) {
                    if (preg_match($search, $filename, $matches)) {
                        $version = $matches[1];
                        break;
                    }
                }
            }
            if (version_compare($version, $minversion) < 0) {
                $version = '';
            }
        }

        // Include JQuery files.
        if ($version) {
            // Moodle >= 2.7.
            $PAGE->requires->jquery();
            $PAGE->requires->jquery_plugin('ui');
            $PAGE->requires->jquery_plugin('ui.touch-punch', 'qtype_ordering');
        } else {
            // Moodle <= 2.6.
            $jquery = '/question/type/' . $this->name().'/jquery';
            $PAGE->requires->js($jquery.'/jquery.js', true);
            $PAGE->requires->js($jquery.'/jquery-ui.js', true);
            $PAGE->requires->js($jquery.'/jquery-ui.touch-punch.js', true);
        }
    }

    protected function initialise_question_instance(question_definition $question, $questiondata) {
        parent::initialise_question_instance($question, $questiondata);
        $this->initialise_combined_feedback($question, $questiondata);
    }

    public function save_question_options($question) {
        global $DB;

        $result = new stdClass();
        $context = $question->context;

        // Remove empty answers.
        $question->answer = array_filter($question->answer, array($this, 'is_not_blank'));
        $question->answer = array_values($question->answer); // Make keys sequential.

        // Count how many answers we have.
        $countanswers = count($question->answer);

        // Search/replace strings to reduce simple <p>...</p> to plain text.
        $psearch = '/^\s*<p>\s*(.*?)(\s*<br\s*\/?>)*\s*<\/p>\s*$/';
        $preplace = '$1';

        // Search/replace strings to standardize vertical align of <img> tags.
        $imgsearch = '/(<img[^>]*)\bvertical-align:\s*[a-zA-Z0-9_-]+([^>]*>)/';
        $imgreplace = '$1'.'vertical-align:text-top'.'$2';

        // Check at least two answers exist.
        if ($countanswers < 2) {
            $result->notice = get_string('notenoughanswers', 'qtype_ordering', '2');
            return $result;
        }

        $question->feedback = range(1, $countanswers);

        if ($answerids = $DB->get_records('question_answers', array('question' => $question->id), 'id ASC', 'id,question')) {
            $answerids = array_keys($answerids);
        } else {
            $answerids = array();
        }

        // Insert all the new answers.
        foreach ($question->answer as $i => $answer) {

            // Extract $answer fields.
            $answertext   = $answer['text'];
            $answerformat = $answer['format'];
            if (!empty($answer['itemid'])) {
                $answeritemid = $answer['itemid'];
            } else {
                $answeritemid = null;
            }

            // Reduce simple <p>...</p> to plain text.
            if (substr_count($answertext, '<p>') == 1) {
                $answertext = preg_replace($psearch, $preplace, $answertext);
            }
            $answertext = trim($answertext);

            // Skip empty answers.
            if ($answertext == '') {
                continue;
            }

            // Standardize vertical align of img tags.
            $answertext = preg_replace($imgsearch, $imgreplace, $answertext);

            // Prepare the $answer object.
            $answer = (object)array(
                'question'       => $question->id,
                'fraction'       => ($i + 1), // Start at 1.
                'answer'         => $answertext,
                'answerformat'   => $answerformat,
                'feedback'       => '',
                'feedbackformat' => FORMAT_MOODLE,
            );

            // Add/insert $answer into the database.
            if ($answer->id = array_shift($answerids)) {
                if (! $DB->update_record('question_answers', $answer)) {
                    $result->error = get_string('cannotupdaterecord', 'error', 'question_answers (id='.$answer->id.')');
                    return $result;
                }
            } else {
                unset($answer->id);
                if (! $answer->id = $DB->insert_record('question_answers', $answer)) {
                    $result->error = get_string('cannotinsertrecord', 'error', 'question_answers');
                    return $result;
                }
            }

            // Copy files across from draft files area.
            // Note: we must do this AFTER inserting the answer record
            // because the answer id is used as the file's "itemid".
            if ($answeritemid) {
                $answertext = file_save_draft_area_files($answeritemid, $context->id, 'question', 'answer', $answer->id,
                        $this->fileoptions, $answertext);
                $DB->set_field('question_answers', 'answer', $answertext, array('id' => $answer->id));
            }
        }

        // Create $options for this ordering question.
        $options = (object)array(
            'questionid' => $question->id,
            'layouttype' => $question->layouttype,
            'selecttype' => $question->selecttype,
            'selectcount' => $question->selectcount,
            'gradingtype' => $question->gradingtype
        );
        $options = $this->save_combined_feedback_helper($options, $question, $context, true);
        $this->save_hints($question, false);

        // Add/update $options for this ordering question.
        if ($options->id = $DB->get_field('qtype_ordering_options', 'id', array('questionid' => $question->id))) {
            if (! $DB->update_record('qtype_ordering_options', $options)) {
                $result->error = get_string('cannotupdaterecord', 'error', 'qtype_ordering_options (id='.$options->id.')');
                return $result;
            }
        } else {
            unset($options->id);
            if (! $options->id = $DB->insert_record('qtype_ordering_options', $options)) {
                $result->error = get_string('cannotinsertrecord', 'error', 'qtype_ordering_options');
                return $result;
            }
        }

        // Delete old answer records, if any.
        if (count($answerids)) {
            $fs = get_file_storage();
            foreach ($answerids as $answerid) {
                $fs->delete_area_files($context->id, 'question', 'answer', $answerid);
                $DB->delete_records('question_answers', array('id' => $answerid));
            }
        }

        return true;
    }

    public function get_possible_responses($questiondata) {
        $responses = array();
        $question = $this->make_question($questiondata);
        foreach ($question->correctresponse as $position => $answerid) {
            $responses[] = $position.': '.$question->answers[$answerid]->answer;
        }
        $responses = array(
            0 => question_possible_response::no_response(),
            1 => implode(', ', $responses)
        );
        return;
    }

    public function is_not_blank($value) {
        if (is_array($value)) {
            $value = $value['text'];
        }
        $value = trim($value);
        return ($value || $value === '0');
    }

    public function get_question_options($question) {
        global $DB, $OUTPUT;

        // Load the options.
        if (!$question->options = $DB->get_record('qtype_ordering_options', array('questionid' => $question->id))) {
            echo $OUTPUT->notification('Error: Missing question options!');
            return false;
        }

        // Load the answers - "fraction" is used to signify the order of the answers.
        if (!$question->options->answers = $DB->get_records('question_answers',
                array('question' => $question->id), 'fraction ASC')) {
            echo $OUTPUT->notification('Error: Missing question answers for ordering question ' . $question->id . '!');
            return false;
        }

        parent::get_question_options($question);
        return true;
    }

    public function delete_question($questionid, $contextid) {
        global $DB;
        $DB->delete_records('qtype_ordering_options', array('questionid' => $questionid));
        parent::delete_question($questionid, $contextid);
    }

    /**
     * import_from_gift
     *
     * @param array         $data
     * @param stdClass      $question
     * @param qformat_gift $format
     * @param string        $extra (optional, default=null)
     * @todo Finish documenting this function
     */
    public function import_from_gift($lines, $question, $format, $extra=null) {
        global $CFG;
        require_once($CFG->dirroot.'/question/type/ordering/question.php');

        // Convert $lines to a single a string - for preg_match().
        $lines = implode(PHP_EOL, $lines);

        // Extract question info from GIFT file $lines.
        $questionname = '[^{]*';
        $selectcount = '\d+';
        $selecttype  = '(?:ALL|EXACT|'.
                          'RANDOM|REL|'.
                          'CONTIGUOUS|CONTIG)?';
        $layouttype  = '(?:HORIZONTAL|HORI|H|1|'.
                          'VERTICAL|VERT|V|0)?';
        $gradingtype = '(?:ALL_OR_NOTHING|'.
                          'ABSOLUTE_POSITION|'.
                          'ABSOLUTE|ABS|'.
                          'RELATIVE_NEXT_EXCLUDE_LAST|'.
                          'RELATIVE_NEXT_INCLUDE_LAST|'.
                          'RELATIVE_ONE_PREVIOUS_AND_NEXT|'.
                          'RELATIVE_ALL_PREVIOUS_AND_NEXT|'.
                          'RELATIVE|REL'.
                          'LONGEST_ORDERED_SUBSET|'.
                          'LONGEST_CONTIGUOUS_SUBSET)?';
        $search = '/^(' . $questionname . ')\s*\{>\s*(' . $selectcount . ')\s*(' . $selecttype . ')\s*' .
                '(' . $layouttype . ')\s*(' . $gradingtype . ')\s*(.*?)\s*\}\s*$/s';
        // Item $1 the question name.
        // Item $2 the number of items to be shown.
        // Item  $3 the extraction/grading type.
        // Item $4 the layout type.
        // Item  $5 the grading type.
        // Item $6 the lines of items to be ordered.
        if (empty($extra) || ! preg_match($search, $lines, $matches)) {
            return false; // Format not recognized.
        }

        $questionname = trim($matches[1]);
        $selectcount = trim($matches[2]);
        $selecttype = trim($matches[3]);
        $layouttype = trim($matches[4]);
        $gradingtype = trim($matches[5]);
        $lines = explode(PHP_EOL, $matches[6]);
        unset($matches);

        $question->qtype = 'ordering';
        $question->name = trim($question->name);

        // Fix empty or long question name.
        $question->name = $this->fix_questionname($question->name, $questionname);

        // Set "selectcount" field from $selectcount.
        if (is_numeric($selectcount) && $selectcount > 2 && $selectcount <= count($lines)) {
            $selectcount = intval($selectcount);
        } else {
            $selectcount = min(6, count($lines));
        }
        $this->set_layout_select_count_grading($question, $layouttype, $selecttype, $selectcount, $gradingtype);

        // Remove blank items.
        $lines = array_map('trim', $lines);
        $lines = array_filter($lines); // Remove blanks.

        // Set up answer arrays.
        $question->answer = array();
        $question->answerformat = array();
        $question->fraction = array();
        $question->feedback = array();
        $question->feedbackformat = array();

        // Note that "fraction" field is used to denote sort order
        // "fraction" fields will be set to correct values later
        // in the save_question_options() method of this class.

        foreach ($lines as $i => $line) {
            $question->answer[$i] = $line;
            $question->answerformat[$i] = FORMAT_MOODLE;
            $question->fraction[$i] = 1; // Will be reset later in save_question_options().
            $question->feedback[$i] = '';
            $question->feedbackformat[$i] = FORMAT_MOODLE;
        }

        // Check that the required feedback fields exist.
        $this->check_ordering_combined_feedback($question);

        return $question;
    }

    /**
     * check_ordering_combined_feedback
     *
     * @param stdClass $question (passed by reference)
     * @todo Finish documenting this function
     */
    protected function check_ordering_combined_feedback(&$question) {
        foreach ($this->feedbackfields as $field) {
            if (empty($question->$field)) {
                $question->$field = array('text' => '', 'format' => FORMAT_MOODLE, 'itemid' => 0, 'files' => null);
            }
        }
    }

    /**
     * extract_layout_select_count_grading
     *
     * @param stdClass $question
     * @todo Finish documenting this function
     */
    public function extract_layout_select_count_grading($question) {

        switch ($question->options->layouttype) {
            case qtype_ordering_question::LAYOUT_VERTICAL:
                $layout = 'VERTICAL';
                break;
            case qtype_ordering_question::LAYOUT_HORIZONTAL:
                $layout = 'HORIZONTAL';
                break;
            default:
                $layout = ''; // Shouldn't happen !!
        }

        switch ($question->options->selecttype) {
            case qtype_ordering_question::SELECT_ALL:
                $select = 'ALL';
                break;
            case qtype_ordering_question::SELECT_RANDOM:
                $select = 'RANDOM';
                break;
            case qtype_ordering_question::SELECT_CONTIGUOUS:
                $select = 'CONTIGUOUS';
                break;
            default:
                $select = ''; // Shouldn't happen !!
        }

        switch ($question->options->gradingtype) {
            case qtype_ordering_question::GRADING_ALL_OR_NOTHING:
                $grading = 'ALL_OR_NOTHING';
                break;
            case qtype_ordering_question::GRADING_ABSOLUTE_POSITION:
                $grading = 'ABSOLUTE_POSITION';
                break;
            case qtype_ordering_question::GRADING_RELATIVE_NEXT_EXCLUDE_LAST:
                $grading = 'RELATIVE_NEXT_EXCLUDE_LAST';
                break;
            case qtype_ordering_question::GRADING_RELATIVE_NEXT_INCLUDE_LAST:
                $grading = 'RELATIVE_NEXT_INCLUDE_LAST';
                break;
            case qtype_ordering_question::GRADING_RELATIVE_ONE_PREVIOUS_AND_NEXT:
                $grading = 'RELATIVE_ONE_PREVIOUS_AND_NEXT';
                break;
            case qtype_ordering_question::GRADING_RELATIVE_ALL_PREVIOUS_AND_NEXT:
                $grading = 'RELATIVE_ALL_PREVIOUS_AND_NEXT';
                break;
            case qtype_ordering_question::GRADING_LONGEST_ORDERED_SUBSET:
                $grading = 'LONGEST_ORDERED_SUBSET';
                break;
            case qtype_ordering_question::GRADING_LONGEST_CONTIGUOUS_SUBSET:
                $grading = 'LONGEST_CONTIGUOUS_SUBSET';
                break;
            default:
                $grading = ''; // Shouldn't happen !!
        }

        // Note: this used to be (selectcount + 2).
        $count = $question->options->selectcount;

        return array($layout, $select, $count, $grading);
    }

    /**
     * export_to_gift
     *
     * @param stdClass      $question
     * @param qformat_gift $format
     * @param string        $extra (optional, default=null)
     * @todo Finish documenting this function
     */
    public function export_to_gift($question, $format, $extra=null) {
        global $CFG;
        require_once($CFG->dirroot.'/question/type/ordering/question.php');

        list($layouttype, $selecttype, $selectcount, $gradingtype) = $this->extract_layout_select_count_grading($question);
        $output = $question->questiontext.'{>'.$selectcount.' '.
                                               $selecttype.' '.
                                               $layouttype.' '.
                                               $gradingtype."\n";
        foreach ($question->options->answers as $answer) {
            $output .= $answer->answer."\n";
        }
        $output .= '}';
        return $output;
    }

    /**
     * export_to_xml
     *
     * @param stdClass    $question
     * @param qformat_xml $format
     * @param string      $extra (optional, default=null)
     * @todo Finish documenting this function
     */
    public function export_to_xml($question, qformat_xml $format, $extra=null) {
        global $CFG;
        require_once($CFG->dirroot.'/question/type/ordering/question.php');

        list($layouttype, $selecttype, $selectcount, $gradingtype) = $this->extract_layout_select_count_grading($question);

        $output = '';
        $output .= "    <layouttype>$layouttype</layouttype>\n";
        $output .= "    <selecttype>$selecttype</selecttype>\n";
        $output .= "    <selectcount>$selectcount</selectcount>\n";
        $output .= "    <gradingtype>$gradingtype</gradingtype>\n";
        $output .= $format->write_combined_feedback($question->options, $question->id, $question->contextid);

        foreach ($question->options->answers as $answer) {
            $output .= '    <answer fraction="'.$answer->fraction.'" '.$format->format($answer->answerformat).">\n";
            $output .= $format->writetext($answer->answer, 3);
            $output .= $format->write_files($answer->answerfiles);
            if ($feedback = trim($answer->feedback)) { // Usually there is no feedback.
                $output .= '      <feedback '.$format->format($answer->feedbackformat).">\n";
                $output .= $format->writetext($answer->feedback, 4);
                $output .= $format->write_files($answer->feedbackfiles);
                $output .= "      </feedback>\n";
            }
            $output .= "    </answer>\n";
        }

        return $output;
    }

    /*
     * Imports question from the Moodle XML format
     *
     * Imports question using information from extra_question_fields function
     * If some of you fields contains id's you'll need to reimplement this
     *
     * @param array          $data
     * @param qtype_ordering $question (or null)
     * @param qformat_xml    $format
     * @param string         $extra (optional, default=null)
     */
    public function import_from_xml($data, $question, qformat_xml $format, $extra=null) {
        global $CFG;
        require_once($CFG->dirroot.'/question/type/ordering/question.php');

        $questiontype = $format->getpath($data, array('@', 'type'), '');

        if ($questiontype != 'ordering') {
            return false;
        }

        $newquestion = $format->import_headers($data);
        $newquestion->qtype = $questiontype;

        // Fix empty or long question name.
        $newquestion->name = $this->fix_questionname($newquestion->name, $newquestion->questiontext);

        // Extra fields - "selecttype" and "selectcount"
        // (these fields used to be called "logical" and "studentsee").
        if (isset($data['#']['selecttype'])) {
            $selecttype = 'selecttype';
            $selectcount = 'selectcount';
        } else {
            $selecttype = 'logical';
            $selectcount = 'studentsee';
        }
        $layouttype = $format->getpath($data, array('#', 'layouttype', 0, '#'), 'VERTICAL');
        $selecttype = $format->getpath($data, array('#', $selecttype, 0, '#'), 'RANDOM');
        $selectcount = $format->getpath($data, array('#', $selectcount, 0, '#'), 6);
        $gradingtype = $format->getpath($data, array('#', 'gradingtype', 0, '#'), 'RELATIVE');
        $this->set_layout_select_count_grading($newquestion, $layouttype, $selecttype, $selectcount, $gradingtype);

        $newquestion->answer = array();
        $newquestion->answerformat = array();
        $newquestion->fraction = array();
        $newquestion->feedback = array();
        $newquestion->feedbackformat = array();

        $i = 0;
        while ($answer = $format->getpath($data, array('#', 'answer', $i), '')) {
            $ans = $format->import_answer($answer, true, $format->get_format($newquestion->questiontextformat));
            $newquestion->answer[$i] = $ans->answer;
            $newquestion->fraction[$i] = 1; // Will be reset later in save_question_options().
            $newquestion->feedback[$i] = $ans->feedback;
            $i++;
        }

        $format->import_combined_feedback($newquestion, $data, false);
        // Check that the required feedback fields exist.
        $this->check_ordering_combined_feedback($newquestion);

        $format->import_hints($newquestion, $data, false);

        return $newquestion;
    }

    /*
     * fix_questionname
     *
     * @param string $name
     * @param string $defaultname (optional, default='')
     * @param integer $maxnamelength (optional, default=42)
     */
    public function fix_questionname($name, $defaultname='', $maxnamelength = 42) {
        if (trim($name) == '') {
            if ($defaultname) {
                $name = $defaultname;
            } else {
                $name = get_string('defaultquestionname', 'qtype_ordering');
            }
        }
        if (strlen($name) > $maxnamelength) {
            $name = substr($name, 0, $maxnamelength);
            if ($pos = strrpos($name, ' ')) {
                $name = substr($name, 0, $pos);
            }
            $name .= ' ...';
        }
        return $name;
    }

    /*
     * set_layout_select_count_grading
     *
     * @param object $question (passed by reference)
     * @param integer $layout the layout type
     * @param integer $select the select type
     * @param integer $count the number of items to display
     * @param integer $grading the grading type
     */
    public function set_layout_select_count_grading(&$question, $layout, $select, $count, $grading) {

        // Set default values.
        $layouttype  = qtype_ordering_question::LAYOUT_VERTICAL;
        $selecttype  = qtype_ordering_question::SELECT_RANDOM;
        $selectcount = 3;
        $gradingtype = qtype_ordering_question::GRADING_RELATIVE_NEXT_EXCLUDE_LAST;

        switch (strtoupper($layout)) {

            case 'HORIZONTAL':
            case 'HORI':
            case 'H':
            case '1':
                $layouttype = qtype_ordering_question::LAYOUT_HORIZONTAL;
                break;

            case 'VERTICAL':
            case 'VERT':
            case 'V':
            case '0':
                $layouttype = qtype_ordering_question::LAYOUT_VERTICAL;
                break;
        }

        // Set "selecttype" from $select.
        switch (strtoupper($select)) {
            case 'ALL':
            case 'EXACT':
                $selecttype = qtype_ordering_question::SELECT_ALL;
                break;
            case 'RANDOM':
            case 'REL':
                $selecttype = qtype_ordering_question::SELECT_RANDOM;
                break;
            case 'CONTIGUOUS':
            case 'CONTIG':
                $selecttype = qtype_ordering_question::SELECT_CONTIGUOUS;
                break;
        }

        // Set "selectcount" from $count
        // this used to be ($count - 2).
        if (is_numeric($count)) {
            $selectcount = intval($count);
        }

        // Set "gradingtype" from $grading.
        switch (strtoupper($grading)) {
            case 'ALL_OR_NOTHING':
                $gradingtype = qtype_ordering_question::GRADING_ALL_OR_NOTHING;
                break;
            case 'ABS':
            case 'ABSOLUTE':
            case 'ABSOLUTE_POSITION':
                $gradingtype = qtype_ordering_question::GRADING_ABSOLUTE_POSITION;
                break;
            case 'REL':
            case 'RELATIVE':
            case 'RELATIVE_NEXT_EXCLUDE_LAST':
                $gradingtype = qtype_ordering_question::GRADING_RELATIVE_NEXT_EXCLUDE_LAST;
                break;
            case 'RELATIVE_NEXT_INCLUDE_LAST':
                $gradingtype = qtype_ordering_question::GRADING_RELATIVE_NEXT_INCLUDE_LAST;
                break;
            case 'RELATIVE_ONE_PREVIOUS_AND_NEXT':
                $gradingtype = qtype_ordering_question::GRADING_RELATIVE_ONE_PREVIOUS_AND_NEXT;
                break;
            case 'RELATIVE_ALL_PREVIOUS_AND_NEXT':
                $gradingtype = qtype_ordering_question::GRADING_RELATIVE_ALL_PREVIOUS_AND_NEXT;
                break;
            case 'LONGEST_ORDERED_SUBSET':
                $gradingtype = qtype_ordering_question::GRADING_LONGEST_ORDERED_SUBSET;
                break;
            case 'LONGEST_CONTIGUOUS_SUBSET':
                $gradingtype = qtype_ordering_question::GRADING_LONGEST_CONTIGUOUS_SUBSET;
                break;
        }

        $question->layouttype  = $layouttype;
        $question->selecttype  = $selecttype;
        $question->selectcount = $selectcount;
        $question->gradingtype = $gradingtype;
    }
}
