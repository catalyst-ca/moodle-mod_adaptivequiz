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

namespace mod_adaptivequiz\local\catalgorithm;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/adaptivequiz/locallib.php');

use advanced_testcase;
use coding_exception;
use mod_adaptivequiz\local\question\question_answer_evaluation_result;
use mod_adaptivequiz\local\question\questions_answered_summary;
use mod_adaptivequiz\local\report\questions_difficulty_range;
use question_usage_by_activity;
use stdClass;

/**
 * Unit tests for the catalgo class.
 *
 * @package    mod_adaptivequiz
 * @copyright  2013 Remote-Learner {@link http://www.remote-learner.ca/}
 * @copyright  2022 onwards Vitaly Potenko <potenkov@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @group mod_adaptivequiz
 * @covers \mod_adaptivequiz\local\catalgo
 */
class catalgo_test extends advanced_testcase {

    /**
     * This function loads data into the PHPUnit tables for testing
     *
     * @throws coding_exception
     */
    protected function setup_test_data_xml() {
        $this->dataset_from_files(
            [__DIR__.'/../fixtures/mod_adaptivequiz_catalgo.xml']
        )->to_database();
    }

    /**
     * This function tests instantiating the catalgo class without setting the level argument.
     */
    public function test_init_catalgo_no_level_throw_except() {
        $this->expectException('coding_exception');
        new catalgo(true);
    }

    public function test_it_can_define_current_difficulty_level(): void {
        $adaptivequiz = new stdClass();
        $adaptivequiz->lowestlevel = 0;
        $adaptivequiz->highestlevel = 100;

        // In case the quba object cannot get any slots.
        $quba = $this->createPartialMock(question_usage_by_activity::class, ['get_slots']);
        $quba->expects($this->once())
            ->method('get_slots')
            ->willReturn([]);

        $catalgo = new catalgo(true, 1);
        $difficultylevel = $catalgo->get_current_diff_level(
            $quba,
            1,
            questions_difficulty_range::from_activity_instance($adaptivequiz)
        );

        $this->assertEquals(0, $difficultylevel);

        // In case compute_next_difficulty() method does the job.
        $quba = $this->getMockBuilder(question_usage_by_activity::class)
            ->disableOriginalConstructor()
            ->getMock();

        $quba->expects($this->once())
            ->method('get_slots')
            ->willReturn([1, 2, 3, 4, 5]);

        $questionstate = $this->createMock('question_state_gradedright');
        $quba->expects($this->once())
            ->method('get_question_state')
            ->with(5)
            ->will($this->returnValue($questionstate));

        $quba->expects($this->exactly(5))
            ->method('get_question_mark')
            ->will($this->returnValue(1.0));

        $catalgo = $this->createPartialMock(catalgo::class, ['compute_next_difficulty']);
        $catalgo->expects($this->exactly(5))
            ->method('compute_next_difficulty')
            ->willReturn(5);

        $difficultylevel = $catalgo->get_current_diff_level(
            $quba,
            1,
            questions_difficulty_range::from_activity_instance($adaptivequiz)
        );

        $this->assertEquals(5, $difficultylevel);
    }

    /**
     * This function tests compute_next_difficulty().
     * Setting 0 as the lowest level and 100 as the highest level.
     */
    public function test_compute_next_difficulty_zero_min_one_hundred_max() {
        $catalgo = new catalgo(true, 1);

        $adaptivequiz = new stdClass();
        $adaptivequiz->lowestlevel = 0;
        $adaptivequiz->highestlevel = 100;

        $questionsdifficultyrange = questions_difficulty_range::from_activity_instance($adaptivequiz);

        // Test the next difficulty level shown to the student if the student got a level 30 question wrong,
        // having attempted 1 question.
        $result = $catalgo->compute_next_difficulty(30, 1, false, $questionsdifficultyrange);
        $this->assertEquals(5, $result);

        // Test the next difficulty level shown to the student if the student got a level 30 question right,
        // having attempted 1 question.
        $result = $catalgo->compute_next_difficulty(30, 1, true, $questionsdifficultyrange);
        $this->assertEquals(76, $result);

        // Test the next difficulty level shown to the student if the student got a level 80 question wrong,
        // having attempted 2 questions.
        $result = $catalgo->compute_next_difficulty(80, 2, false, $questionsdifficultyrange);
        $this->assertEquals(60, $result);

        // Test the next difficulty level shown to the student if the student got a level 80 question right,
        // having attempted 2 question.
        $result = $catalgo->compute_next_difficulty(80, 2, true, $questionsdifficultyrange);
        $this->assertEquals(92, $result);
    }

    /**
     * This function tests compute_next_difficulty().
     * Setting 1 as the lowest level and 10 as the highest level.
     */
    public function test_compute_next_difficulty_one_min_ten_max_compute_infinity() {
        $catalgo = new catalgo(true, 1);

        $adaptivequiz = new stdClass();
        $adaptivequiz->lowestlevel = 1;
        $adaptivequiz->highestlevel = 10;

        $questionsdifficultyrange = questions_difficulty_range::from_activity_instance($adaptivequiz);

        $result = $catalgo->compute_next_difficulty(1, 2, false, $questionsdifficultyrange);
        $this->assertEquals(1, $result);

        $result = $catalgo->compute_next_difficulty(10, 2, true, $questionsdifficultyrange);
        $this->assertEquals(10, $result);
    }

    /**
     * This function tests results returned from get_question_mark().
     */
    public function test_get_question_mark() {
        // Test quba returning a mark of 1.0.
        $mockquba = $this->createMock(question_usage_by_activity::class);

        $mockquba->expects($this->once())
            ->method('get_question_mark')
            ->will($this->returnValue(1.0));

        $catalgo = new catalgo(true, 1);
        $result = $catalgo->get_question_mark($mockquba, 1);
        $this->assertEquals(1.0, $result);

        // Test quba returning a non float value.
        $mockqubatwo = $this->createMock('question_usage_by_activity');

        $mockqubatwo->expects($this->once())
            ->method('get_question_mark')
            ->will($this->returnValue(1));

        $catalgo = new catalgo(true, 1);
        $result = $catalgo->get_question_mark($mockqubatwo, 1);
        $this->assertNull($result);
    }

    /**
     * This function tests the return data from estimate_measure().
     */
    public function test_estimate_measure() {
        // Test an attempt with the following details:
        // sum of difficulty - 20, number of questions attempted - 10, number of correct answers - 7,
        // number of incorrect answers - 3.
        $catalgo = new catalgo(true, 1);
        $result = $catalgo->estimate_measure(20, 10, 7, 3);
        $this->assertEquals(2.8473, $result);
    }

    /**
     * This function tests the return data from estimate_standard_error().
     */
    public function test_estimate_standard_error() {
        // Test an attempt with the following details;
        // sum of questions attempted - 10, number of correct answers - 7, number of incorrect answers - 3.
        $catalgo = new catalgo(true, 1);
        $result = $catalgo->estimate_standard_error(10, 7, 3);
        $this->assertEquals(0.69007, $result);
    }

    public function test_it_determines_next_difficulty_as_with_error_when_question_was_not_answered(): void {
        self::resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $adaptivequiz = $this->getDataGenerator()
            ->get_plugin_generator('mod_adaptivequiz')
            ->create_instance([
                'highestlevel' => 10,
                'lowestlevel' => 1,
                'standarderror' => 5,
                'course' => $course->id
            ]);

        $catalgo = new catalgo(false, 1);

        $determinenextdifficultylevelresult = $catalgo->determine_next_difficulty_level(
            1,
            5,
            questions_difficulty_range::from_activity_instance($adaptivequiz),
            $adaptivequiz->standarderror,
            question_answer_evaluation_result::when_answer_was_not_given(),
            questions_answered_summary::from_integers(2, 4)
        );

        self::assertEquals(
            determine_next_difficulty_result::with_error(get_string('errorlastattpquest', 'adaptivequiz')),
            $determinenextdifficultylevelresult
        );
    }

    public function test_it_determines_next_difficulty_as_with_error_when_number_of_questions_attempted_is_not_valid(): void {
        self::resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $adaptivequiz = $this->getDataGenerator()
            ->get_plugin_generator('mod_adaptivequiz')
            ->create_instance([
                'highestlevel' => 10,
                'lowestlevel' => 1,
                'standarderror' => 5,
                'course' => $course->id
            ]);

        $catalgo = new catalgo(true, 1);

        $determinenextdifficultylevelresult = $catalgo->determine_next_difficulty_level(
            20,
            10,
            questions_difficulty_range::from_activity_instance($adaptivequiz),
            $adaptivequiz->standarderror,
            question_answer_evaluation_result::when_answer_is_correct(),
            questions_answered_summary::from_integers(1, 1)
        );

        $this->assertEquals(
            determine_next_difficulty_result::with_error(get_string('errorsumrightwrong', 'adaptivequiz')),
            $determinenextdifficultylevelresult
        );
    }

    public function test_it_determines_next_difficulty_when_answer_is_given_and_stopping_criteria_is_not_met(): void {
        self::resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $adaptivequiz = $this->getDataGenerator()
            ->get_plugin_generator('mod_adaptivequiz')
            ->create_instance([
                'highestlevel' => 10,
                'lowestlevel' => 1,
                'standarderror' => 5,
                'course' => $course->id
            ]);

        $catalgo = new catalgo(false, 5);

        // When answer is correct.
        $determinenextdifficultylevelresult = $catalgo->determine_next_difficulty_level(
            1,
            5,
            questions_difficulty_range::from_activity_instance($adaptivequiz),
            $adaptivequiz->standarderror,
            question_answer_evaluation_result::when_answer_is_correct(),
            questions_answered_summary::from_integers(2, 4)
        );

        self::assertEquals(
            determine_next_difficulty_result::with_next_difficulty_level_determined(6),
            $determinenextdifficultylevelresult
        );

        // When answer is not correct.
        $determinenextdifficultylevelresult = $catalgo->determine_next_difficulty_level(
            1,
            5,
            questions_difficulty_range::from_activity_instance($adaptivequiz),
            $adaptivequiz->standarderror,
            question_answer_evaluation_result::when_answer_is_incorrect(),
            questions_answered_summary::from_integers(2, 4)
        );

        self::assertEquals(
            determine_next_difficulty_result::with_next_difficulty_level_determined(4),
            $determinenextdifficultylevelresult
        );
    }

    public function test_it_determines_next_difficulty_when_answer_is_given_and_stopping_criteria_is_met(): void {
        self::resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $adaptivequiz = $this->getDataGenerator()
            ->get_plugin_generator('mod_adaptivequiz')
            ->create_instance([
                'highestlevel' => 10,
                'lowestlevel' => 1,
                'standarderror' => 5,
                'course' => $course->id
            ]);

        $catalgo = new catalgo(false, 5);

        $determinenextdifficultylevelresult = $catalgo->determine_next_difficulty_level(
            50,
            2,
            questions_difficulty_range::from_activity_instance($adaptivequiz),
            $adaptivequiz->standarderror,
            question_answer_evaluation_result::when_answer_is_incorrect(),
            questions_answered_summary::from_integers(1, 2)
        );

        self::assertEquals(
            determine_next_difficulty_result::with_next_difficulty_level_determined(4),
            $determinenextdifficultylevelresult
        );
    }

    /**
     * This function tests the return value from standard_error_within_parameters().
     */
    public function test_standard_error_within_parameters_return_true_then_false() {
        $catalgo = new catalgo(true, 1);
        $result = $catalgo->standard_error_within_parameters(0.02, 0.1);
        $this->assertTrue($result);

        $result = $catalgo->standard_error_within_parameters(0.01, 0.002);
        $this->assertFalse($result);
    }

    /**
     * This function tests the output from convert_percent_to_logit()
     */
    public function test_convert_percent_to_logit_using_param_less_than_zero() {
        $this->expectException('coding_exception');
        $result = catalgo::convert_percent_to_logit(-1);
    }

    /**
     * This function tests the output from convert_percent_to_logit()
     */
    public function test_convert_percent_to_logit_using_param_greater_than_decimal_five() {
        $this->expectException('coding_exception');
        catalgo::convert_percent_to_logit(0.51);
    }

    /**
     * This function tests the output from convert_percent_to_logit()
     */
    public function test_convert_percent_to_logit() {
        $result = catalgo::convert_percent_to_logit(0.05);
        $result = round($result, 1);
        $this->assertEquals(0.2, $result);
    }

    /**
     * This function tests the output from convert_logit_to_percent()
     */
    public function test_convert_logit_to_percent_using_param_less_than_zero() {
        $this->expectException('coding_exception');
        catalgo::convert_logit_to_percent(-1);
    }

    /**
     * This function tests the output from convert_logit_to_percent()
     */
    public function test_convert_logit_to_percent() {
        $result = catalgo::convert_logit_to_percent(0.2);
        $result = round($result, 2);
        $this->assertEquals(0.05, $result);
    }

    /**
     * This function tests the output from map_logit_to_scale()
     */
    public function test_map_logit_to_scale() {
        $result = catalgo::map_logit_to_scale(-0.6, 16, 1);
        $result = round($result, 1);
        $this->assertEquals(6.3, $result);
    }
}
