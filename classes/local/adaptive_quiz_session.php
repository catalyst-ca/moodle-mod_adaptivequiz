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

namespace mod_adaptivequiz\local;

use coding_exception;
use core_component;
use core_tag_tag;
use mod_adaptivequiz\local\attempt\attempt;
use mod_adaptivequiz\local\attempt\cat_calculation_steps_result;
use mod_adaptivequiz\local\attempt\cat_model_params;
use mod_adaptivequiz\local\catalgorithm\catalgo;
use mod_adaptivequiz\local\catalgorithm\difficulty_logit;
use mod_adaptivequiz\local\itemadministration\default_item_administration_factory;
use mod_adaptivequiz\local\itemadministration\item_administration_evaluation;
use mod_adaptivequiz\local\itemadministration\item_administration_factory;
use mod_adaptivequiz\local\question\question_answer_evaluation_result;
use mod_adaptivequiz\local\question\questions_answered_summary_provider;
use mod_adaptivequiz\local\report\questions_difficulty_range;
use question_engine;
use question_usage_by_activity;
use stdClass;

/**
 * Entry point service to manage process of CAT.
 *
 * @package    mod_adaptivequiz
 * @copyright  2023 Vitaly Potenko <potenkov@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class adaptive_quiz_session {

    /**
     * @var item_administration_factory $itemadministrationfactory
     */
    private $itemadministrationfactory;

    /**
     * @var question_usage_by_activity $quba
     */
    private $quba;

    /**
     * @var stdClass $adaptivequiz Configuration of the activity instance.
     */
    private $adaptivequiz;

    /**
     * The constructor, closed.
     *
     * The init() factory method must be used instead.
     *
     * @param item_administration_factory $itemadministrationfactory
     * @param question_usage_by_activity $quba
     * @param stdClass $adaptivequiz
     */
    private function __construct(
        item_administration_factory $itemadministrationfactory,
        question_usage_by_activity $quba,
        stdClass $adaptivequiz
    ) {
        $this->itemadministrationfactory = $itemadministrationfactory;
        $this->quba = $quba;
        $this->adaptivequiz = $adaptivequiz;
    }

    /**
     * Runs when answer was submitted to a question (item).
     *
     * @param attempt $attempt
     * @param stdClass $adaptivequiz
     * @param int $attemptedslot Quba slot.
     */
    public function process_item_result(attempt $attempt, stdClass $adaptivequiz, int $attemptedslot): void {
        $currenttime = time();

        $this->quba->process_all_actions($currenttime);
        $this->quba->finish_all_questions($currenttime);
        question_engine::save_questions_usage_by_activity($this->quba);

        $attempt->update_after_question_answered($currenttime);

        if ($adaptivequiz->catmodel) {
            return;
        }

        $attempteddifficultylevel = $this->obtain_difficulty_level_of_question($attemptedslot);

        $catmodelparams = cat_model_params::for_attempt($attempt->read_attempt_data()->id);

        $questionsdifficultyrange = questions_difficulty_range::from_activity_instance($this->adaptivequiz);

        $answersummary = (new questions_answered_summary_provider($this->quba))->collect_summary();

        // Map the linear scale to a logarithmic logit scale.
        $logit = catalgo::convert_linear_to_logit($attempteddifficultylevel, $questionsdifficultyrange);

        $questionsattempted = $attempt->read_attempt_data()->questionsattempted;
        $standarderror = catalgo::estimate_standard_error($questionsattempted, $answersummary->number_of_correct_answers(),
            $answersummary->number_of_wrong_answers());

        $measure = catalgo::estimate_measure(
            difficulty_logit::from_float($catmodelparams->get('difficultysum'))
                ->summed_with_another_logit(difficulty_logit::from_float($logit))->as_float(),
            $questionsattempted,
            $answersummary->number_of_correct_answers(),
            $answersummary->number_of_wrong_answers()
        );

        $catmodelparams->update_with_calculation_steps_result(
            cat_calculation_steps_result::from_floats($logit, $standarderror, $measure)
        );

        // An answer was submitted, decrement the sum of questions for the attempted difficulty level.
        fetchquestion::decrement_question_sum_for_difficulty_level($attempteddifficultylevel);
    }

    /**
     * A wrapper around item administration service.
     *
     * Obtains implementation of item administration from the factory and runs its evaluating method.
     *
     * @param attempt $attempt
     * @param question_answer_evaluation_result|null $previousanswerevaluation
     * @return item_administration_evaluation
     */
    public function run_item_administration_evaluation(
        attempt $attempt,
        ?question_answer_evaluation_result $previousanswerevaluation
    ): item_administration_evaluation {
        $evaluationresult = $this->itemadministrationfactory->item_administration_implementation($this->quba, $attempt, $this->adaptivequiz)
            ->evaluate_ability_to_administer_next_item($previousanswerevaluation);

        $attempt->set_quba_id($this->quba->get_id());

        return $evaluationresult;
    }

    /**
     * Instantiates an object with proper dependencies.
     *
     * Searches for implementation of the item administration factory when a custom CAT model is chose for the quiz.
     *
     * @param question_usage_by_activity $quba
     * @param stdClass $adaptivequiz
     * @return self
     */
    public static function init(question_usage_by_activity $quba, stdClass $adaptivequiz): self {
        if ($adaptivequiz->catmodel) {
            $itemadministrationfactory = self::catmodel_item_administration_factory($adaptivequiz);

            return new self($itemadministrationfactory, $quba, $adaptivequiz);
        }

        return new self(new default_item_administration_factory(), $quba, $adaptivequiz);
    }

    /**
     * Instantiates attempt object for both fresh and continued attempt cases.
     *
     * @param stdClass $adaptivequiz
     * @return attempt
     */
    public static function initialize_attempt(stdClass $adaptivequiz): attempt {
        global $USER;

        $attempt = attempt::find_in_progress_for_user($adaptivequiz, $USER->id);
        if ($attempt === null) {
            $attempt = attempt::create($adaptivequiz, $USER->id);
            self::post_create_attempt($adaptivequiz, $attempt);
        }

        return $attempt;
    }

    /**
     * Tries to instantiate implementation of the factory for the CAT model selected.
     *
     * @param stdClass $adaptivequiz
     * @return item_administration_factory
     * @throws coding_exception
     */
    private static function catmodel_item_administration_factory(stdClass $adaptivequiz): item_administration_factory {
        $implementations = core_component::get_component_classes_in_namespace(
            "adaptivequizcatmodel_$adaptivequiz->catmodel",
            'local\catmodel\itemadministration'
        );
        if (empty($implementations)) {
            throw new coding_exception(
                'implementations of the item_administration_factory interface could not be found for the selected CAT model'
            );
        }

        $classnames = array_filter(array_keys($implementations), function (string $classname): bool {
            return is_subclass_of($classname, '\mod_adaptivequiz\local\itemadministration\item_administration_factory');
        });

        if (empty($classnames)) {
            throw new coding_exception(
                'implementations of the item_administration_factory interface could not be found for the selected CAT model'
            );
        }

        if (count($classnames) > 1) {
            throw new coding_exception('only one implementation of the item_administration_factory interface is expected');
        }

        $classname = array_shift($classnames);

        return new $classname();
    }

    /**
     * Runs actions, which need o be run when a new attempt is created.
     *
     * In case of using a custom CAT model wires up its callback (if defined). Otherwise, runs initialization for the default
     * CAT algorithm.
     *
     * @param stdClass $adaptivequiz
     * @param attempt $attempt
     */
    private static function post_create_attempt(stdClass $adaptivequiz, attempt $attempt): void {
        if ($adaptivequiz->catmodel) {
            self::call_catmodel_post_create_attempt($adaptivequiz, $attempt);

            return;
        }

        cat_model_params::create_new_for_attempt($attempt->read_attempt_data()->id);
    }

    /**
     * Calls custom CAT model's callback if it could be found.
     *
     * When the callback cannot be executed the method silently exits.
     *
     * @param stdClass $adaptivequiz
     * @param attempt $attempt
     */
    private static function call_catmodel_post_create_attempt(stdClass $adaptivequiz, attempt $attempt): void {
        $catmodelcomponentname = 'adaptivequizcatmodel_' . $adaptivequiz->catmodel;
        $pluginswithfunction = get_plugin_list_with_function('adaptivequizcatmodel', 'post_create_attempt_callback');
        if (!array_key_exists($catmodelcomponentname, $pluginswithfunction)) {
            return;
        }

        $functionname = $pluginswithfunction[$catmodelcomponentname];
        $functionname($adaptivequiz, $attempt);
    }

    /**
     * Reaches out to question engine and tags component to get the question difficulty.
     *
     * @param int $attemptedslot
     * @return int Difficulty level as number.
     */
    private function obtain_difficulty_level_of_question(int $attemptedslot): int {
        $question = $this->quba->get_question($attemptedslot);

        $questiontags = core_tag_tag::get_item_tags('core_question', 'question', $question->id);
        $questiontags = array_filter($questiontags, function (core_tag_tag $tag): bool {
            return substr($tag->name, 0, strlen(ADAPTIVEQUIZ_QUESTION_TAG)) === ADAPTIVEQUIZ_QUESTION_TAG;
        });
        $questiontag = array_shift($questiontags);

        return substr($questiontag->name, strlen(ADAPTIVEQUIZ_QUESTION_TAG));
    }
}
