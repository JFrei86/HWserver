<?php

namespace app\models;

use app\exceptions\ValidationException;
use app\libraries\Core;
use app\libraries\database\DatabaseQueries;
use app\libraries\DatabaseUtils;
use app\libraries\DateUtils;
use app\libraries\FileUtils;
use app\libraries\GradeableType;

/**
 * Class RainbowCustomization
 * @package app\models
 *
 * This class is a RainbowGrades Customization.  It may contain the data found in customization.json but it also
 * contains additional data that is used by the web user interface to aid in generation/customization.
 */
class RainbowCustomization extends AbstractModel {
    /**/
    protected $core;
    private $bucket_counts = [];               // Keep track of how many items are in each bucket
    private $customization_data = [];
    private $has_error;
    private $error_messages;
    private $used_buckets = [];
    private $available_buckets;
    private $RCJSON;                           // This is the customization.json php object, or null if it wasn't found
    private $sections;                         // Contains section ids mapped to labels

    /*XXX: This is duplicated from AdminGradeableController.php, we really shouldn't have multiple copies lying around.
     * On top of that, Rainbow Grades has its own enum internally. Since that's a separate repo it's probably
     * unavoidable, but the fewer places we can duplicate this, the better.
     */
    /*XXX: It's also going to be annoying to have "none (for practice only)" since in customization it's "none"
     * which is a lot nicer to type.
     */
    //XXX: 'none (for practice only)' we want to truncate to just 'none'.
    const syllabus_buckets = [
        'homework', 'assignment', 'problem-set',
        'quiz', 'test', 'exam',
        'exercise', 'lecture-exercise', 'reading', 'lab', 'recitation', 'worksheet',
        'project',
        'participation', 'note',
        'none'];


    public function __construct(Core $main_core) {
        parent::__construct($main_core);
        $this->has_error = "false";
        $this->error_messages = [];

        $this->sections = (object) [];

        // Attempt to load json from customization file
        // If it fails then set to null, will be used to load defaults later
        $this->RCJSON = new RainbowCustomizationJSON($this->core);

        try {
            $this->RCJSON->loadFromJsonFile();
        }
        catch (\Exception $e) {
            $this->RCJSON = null;
        }
    }

    public function buildCustomization() {

        //This function should examine the DB(?) / a file(?) and if customization settings already exist, use them. Otherwise, populate with defaults.
        foreach (self::syllabus_buckets as $bucket) {
            $this->customization_data[$bucket] = [];
            $this->bucket_counts[$bucket] = 0;
        }

        $gradeables = $this->core->getQueries()->getGradeableConfigs(null);
        foreach ($gradeables as $gradeable) {
            //XXX: 'none (for practice only)' we want to truncate to just 'none', otherwise use full bucket name
            $bucket = $gradeable->getSyllabusBucket() == "none (for practice only)" ? "none" : $gradeable->getSyllabusBucket();
            /*if(!isset($this->customization_data[$bucket])){
                $this->customization_data[$bucket] = [];
            }*/
            /*XXX: Right now we aren't yet worried about pulling in from existing customization.json but if we do, then what happens in the event of a conflict?
             * I'm tempted to say for version 1.0, too bad, we override with the version from the DB (since that's more up to date), if the gradeable exists
             * In a later version we may want to highlight it in red or something, and ask the user which number to use? I'm not sure if there's a use case for using
             * the version in the customization.json, but the warning might be nice. Might even be an error state where action is required, just so the user isn't
             * confused when the grade distribution shifts around.
             */

            // Update bucket count
            $this->bucket_counts[$bucket]++;
            $max_score = $gradeable->getManualGradingPoints();
            //If the gradeable has autograding points, load the config and add the non-extra-credit autograder total
            if ($gradeable->hasAutogradingConfig()) {
                $last_index = count($this->customization_data[$bucket]) - 1;
                $max_score += $gradeable->getAutogradingConfig()->getTotalNonExtraCredit();
            }
            $this->customization_data[$bucket][] = [
                "id" => $gradeable->getId(),
                "title" => $gradeable->getTitle(),
                "max_score" => $max_score,
                "grade_release_date" => $gradeable->hasReleaseDate() ? DateUtils::dateTimeToString($gradeable->getGradeReleasedDate()) : DateUtils::dateTimeToString($gradeable->getSubmissionOpenDate()),
                "override" => false,
                "override_max" => $max_score
            ];
        }

        // Determine which 'buckets' exist in the customization.json
        if (!is_null($this->RCJSON)) {
            $json_buckets = $this->RCJSON->getGradeables();
            foreach ($json_buckets as $json_bucket) {
                // Place those buckets in $this->used_buckets
                $this->used_buckets[] = $json_bucket->type;

                // When preparing the count of how many items are in the bucket, if the instructor has previously
                // entered a value which was greater than the number of gradeables in the database, we should use the
                // instructor entered value instead
                $bucket = $json_bucket->type;

                if ($json_bucket->count > $this->bucket_counts[$bucket]) {
                    $this->bucket_counts[$bucket] = $json_bucket->count;
                }
            }
        }

        // Reorder buckets
        $this->reorderBuckets();

        //Now that the buckets are ordered and the customization has been initialized, we can
        //loop through to find differences between the max_values from the database vs the customization JSON
        if (!is_null($this->RCJSON) && count($this->RCJSON->getGradeables())) {
            $json_buckets = $this->RCJSON->getGradeables();
            //we have to keep track of the customization bucket and the JSON bucket separately, since the customization
            //has all buckets (even empty ones) while the JSON only has buckets with content in it.
            $c_bucket = "";
            foreach ($json_buckets as $json_bucket) {
                if (property_exists($json_bucket, 'type')) {
                    $c_bucket = $json_bucket->type;
                }
                else {
                    continue;
                }

                //loop through all gradeables in bucket and compare them
                $j_index = 0;
                foreach ($this->customization_data[$c_bucket] as &$c_gradeable) {
                    if ($c_gradeable['max_score'] !== (float) $json_bucket->ids[$j_index]->max) {
                        $c_gradeable['override'] = true;
                        $c_gradeable['override_max'] = $json_bucket->ids[$j_index]->max;
                    }
                    $j_index++;
                }
            }
        }
        //XXX: Assuming that the contents of these buckets will be lowercase
        $this->available_buckets = array_diff(self::syllabus_buckets, $this->used_buckets);
    }

    /**
     * Reorder each gradeable bucket in $this->customization_data to match JSON (or grade release date as a fallback) ordering
     */
    private function reorderBuckets(): void {
        $json_buckets_gradeables = [];

        // First, fetch JSON and associate ids with buckets
        if (!is_null($this->RCJSON)) {
            $json_buckets = $this->RCJSON->getGradeables();
            foreach ($json_buckets as $json_bucket) {
                if (property_exists($json_bucket, 'type') && property_exists($json_bucket, 'ids')) {
                    $json_buckets_gradeables[$json_bucket->type] = $json_bucket->ids;
                }
            }
        }

        // Reorder individual buckets
        $temp_customization_data = [];
        foreach ($this->customization_data as $bucket => $gradeables) {
            $json_bucket_ids = array_key_exists($bucket, $json_buckets_gradeables) ? $json_buckets_gradeables[$bucket] : [];
            $temp_customization_data[$bucket] = $this->reorderBucket($gradeables, $json_bucket_ids);
        }
        $this->customization_data = $temp_customization_data;
    }

    /**
     * Returns $bucket_gradeables reordered to match order in $json_bucket_ids.
     * If a gradeable is not present in $json_bucket_ids, it will be added to the end of the array in order of grade release date.
     * @param array $bucket_gradeables A gradeable bucket from $this->customization_data
     * @param array $json_bucket_ids An "ids" array from a $this->RCSJSON bucket
     * @return array
     */
    private function reorderBucket(array $bucket_gradeables, array $json_bucket_ids): array {
        $new_gradeables = [];

        // First, associate gradeables with their IDs
        $gradeables_by_id = array_reduce($bucket_gradeables, function ($accumulator, $gradeable) {
            $accumulator[$gradeable['id']] = $gradeable;
            return $accumulator;
        }, []);

        // Then, add gradeables to $new_gradeables based on JSON ordering
        if (!is_null($json_bucket_ids)) {
            foreach ($json_bucket_ids as $json_gradeable) {
                if (property_exists($json_gradeable, 'id')) {
                    $id = $json_gradeable->id;
                    if (array_key_exists($id, $gradeables_by_id)) {
                        $new_gradeables[] = $gradeables_by_id[$id];
                        unset($gradeables_by_id[$id]);
                    }
                }
            }
        }

        // Finally, add any remaining gradeables to $new_gradeables based on date ordering.
        $num_unordered_gradeables = count($gradeables_by_id);
        $gradeables_by_date = [];
        foreach ($gradeables_by_id as $gradeable) {
            $num_gradeables_counted = count($gradeables_by_date);

            // Ensure strings are sorted properly
            $gradeable_count_string = str_repeat(
                '0',
                strlen(strval($num_unordered_gradeables)) - strlen(strval($num_gradeables_counted))
            ) . $num_gradeables_counted;

            if (array_key_exists('grade_release_date', $gradeable)) {
                $gradeables_by_date[$gradeable['grade_release_date'] . '_' . $gradeable_count_string] = $gradeable;
            }
            else {
                $gradeables_by_date['END_OF_TIME_' . $gradeable_count_string] = $gradeable;
            }
        }
        ksort($gradeables_by_date);
        foreach ($gradeables_by_date as $gradeable) {
            $new_gradeables[] = $gradeable;
        }

        return $new_gradeables;
    }

    /**
     * Gets curve data for each gradeable
     *
     * In the case no rainbow grades customization.json was found the return value will be an empty array.
     * In the case a customization.json was found the return array will have the form
     * $retArray[bucket_id][gradeable_id] = curve_values
     *
     * If no curve values were found for gradeable_id then gradeable_id will not be present in the return array
     *
     * @return array
     */
    public function getPerGradeableCurves() {
        $retArray = [];

        if (!is_null($this->RCJSON)) {
            $json_buckets = $this->RCJSON->getGradeables();

            foreach ($json_buckets as $json_bucket) {
                $retArray[$json_bucket->type] = [];

                foreach ($json_bucket->ids as $json_gradeable) {
                    if (property_exists($json_gradeable, 'curve')) {
                        $curve_data = $json_gradeable->curve;
                        $curve_data_pos = 0;
                        $selected_benchmarks = $this->RCJSON->getDisplayBenchmarks();
                        $benchmarks_with_input_fields = array_slice($this->RCJSON::allowed_display_benchmarks, 3);

                        $retArray[$json_bucket->type][$json_gradeable->id] = [];

                        foreach ($benchmarks_with_input_fields as $benchmark) {
                            if (in_array($benchmark, $selected_benchmarks)) {
                                $val = $curve_data[$curve_data_pos];
                                $curve_data_pos++;
                            }
                            else {
                                $val = '';
                            }

                            array_push($retArray[$json_bucket->type][$json_gradeable->id], $val);
                        }
                    }
                }
            }
        }

        return $retArray;
    }

    /**
     * Get an array containing what percentage of the grade the bucket counts toward.  The key is the name of the
     * bucket and the value is the percentage which has been cast back to a whole number integer.  This differs
     * from the customization.json in that in the json file the percentage is represented as a decimal between 0 and 1.
     * This value is represented as an integer between 0 and 100.
     *
     * ex.  key => value
     *     'test' => 50
     *     'lab' => 25
     *     'homework' => 25
     *
     * @return array
     */
    public function getBucketPercentages() {
        $retArray = [];

        if (!is_null($this->RCJSON)) {
            $json_buckets = $this->RCJSON->getGradeables();

            $sum = 0;

            foreach ($json_buckets as $json_bucket) {
                // Get percentage, cast back to whole number integer
                $retArray[$json_bucket->type] = (int) ($json_bucket->percent * 100);

                // Keep track of the sum
                $sum += $retArray[$json_bucket->type];
            }

            // Save the sum of used percentages to special key in array
            $retArray['used_percentage'] = $sum;
        }

        return $retArray;
    }

    public function getBucketCounts() {
        return $this->bucket_counts;
    }

    public function getCustomizationData() {
        return $this->customization_data;
    }

    public function getAvailableBuckets() {
        return $this->available_buckets;
    }

    public function getUsedBuckets() {
        return $this->used_buckets;
    }

    public function getMessages() {
        return !is_null($this->RCJSON) ? $this->RCJSON->getMessages() : [];
    }

    /**
     * Get display benchmarks
     *
     * Get a multidimensional array that contains not only a list of usable display benchmarks but also which ones
     * are in use (in the customization.json)
     *
     * @return array multidimensional array of display benchmark data
     */
    public function getDisplayBenchmarks() {
        // Get allowed benchmarks
        $displayBenchmarks = RainbowCustomizationJSON::allowed_display_benchmarks;
        $retArray = [];

        // If json file available then collect used benchmarks from that, else get empty array
        !is_null($this->RCJSON) ?
            $usedDisplayBenchmarks = $this->RCJSON->getDisplayBenchmarks() :
            $usedDisplayBenchmarks = [];

        // Add data into retArray
        foreach ($displayBenchmarks as $displayBenchmark) {
            in_array($displayBenchmark, $usedDisplayBenchmarks) ? $isUsed = true : $isUsed = false;

            // Add benchmark to return array
            $retArray[] = ['id' => $displayBenchmark, 'isUsed' => $isUsed];
        }

        return $retArray;
    }

    /**
     * Get benchmark percentages
     *
     * @return object An object which maps benchmarks to the percentage (as a decimal) that is needed to obtain that
     *                letter grade
     */
    public function getBenchmarkPercent() {
        if (!is_null($this->RCJSON)) {
            $percent_obj = $this->RCJSON->getBenchmarkPercent();

            // If the RCJSON was found and it contains the benchmark percent fields then return it
            if ($percent_obj != (object) []) {
                return $percent_obj;
            }
        }

        // Otherwise return a default benchmark percent object
        return (object) [
                'lowest_a-' => 0.9,
                'lowest_b-' => 0.8,
                'lowest_c-' => 0.7,
                'lowest_d' => 0.6,
            ];
    }

    /**
     * Get section ids and labels
     *
     * If no customization.json file exists then this function will generate defaults
     * by examining what sections are registered in the database.  If a file does exist then sections and labels will
     * be read out of that.  If it turns out that new sections have been registered in the database that
     * dont yet exist in the file, those new sections will be added with default values.
     *
     * @return object The object mapping section ids to labels
     */
    public function getSectionsAndLabels() {
        // Get sections from db
        $db = new DatabaseQueries($this->core);
        $db_sections = $db->getRegistrationSections();

        $sections = [];

        // Configure sections
        foreach ($db_sections as $section) {
            $key = $section['sections_registration_id'];

            $sections[$key] = $key;
        }

        // If RCJSON is not null then load it
        if (!is_null($this->RCJSON)) {
            // Get sections from the file
            $sectionsFromFile = (array) $this->RCJSON->getSection();

            // If sections from database is larger than sections from file then there must be a new section in
            // in the database, add new fields into sections from file with defaults
            $sectionsFromFileCount = count($sectionsFromFile);
            $sectionsCount = count($sections);

            if ($sectionsFromFileCount != $sectionsCount) {
                for ($i = $sectionsFromFileCount + 1; $i <= $sectionsCount; $i++) {
                    $sectionsFromFile[$i] = (string) $i;
                }
            }

            return (object) $sectionsFromFile;
        }
        else {
            // RCJSON was null so return database sections as default
            // Collect sections out of the database
            return (object) $sections;
        }
    }

    // This function handles processing the incoming post data
    public function processForm() {

        // Get a new customization file
        $this->RCJSON = new RainbowCustomizationJSON($this->core);

        $form_json = $_POST['json_string'];
        $form_json = json_decode($form_json);

        if (isset($form_json->display_benchmark)) {
            foreach ($form_json->display_benchmark as $benchmark) {
                $this->RCJSON->addDisplayBenchmarks($benchmark);
            }
        }

        if (isset($form_json->benchmark_percent)) {
            foreach ($form_json->benchmark_percent as $key => $value) {
                $this->RCJSON->addBenchmarkPercent((string) $key, $value);
            }
        }

        if (isset($form_json->section)) {
            foreach ($form_json->section as $key => $value) {
                $this->RCJSON->addSection((string) $key, $value);
            }
        }

        if (isset($form_json->gradeables)) {
            foreach ($form_json->gradeables as $gradeable) {
                $this->RCJSON->addGradeable($gradeable);
            }
        }

        if (isset($form_json->messages)) {
            foreach ($form_json->messages as $message) {
                $this->RCJSON->addMessage($message);
            }
        }

        // Write to customization file
        $this->RCJSON->saveToJsonFile();

        // Configure json to go into jobs queue
        $job_json = (object) [];
        $job_json->job = 'RunAutoRainbowGrades';
        $job_json->semester = $this->core->getConfig()->getSemester();
        $job_json->course = $this->core->getConfig()->getCourse();

        // Encode
        $job_json = json_encode($job_json, JSON_PRETTY_PRINT);

        // Create path to new jobs queue json
        $path = '/var/local/submitty/daemon_job_queue/auto_rainbow_' .
            $this->core->getConfig()->getSemester() .
            '_' .
            $this->core->getConfig()->getCourse() .
            '.json';

        // Place in queue
        file_put_contents($path, $job_json);

//        $this->has_error = "true";
//        foreach($_POST as $field => $value){
//            $this->error_messages[] = "$field: $value";
//        }
//        throw new ValidationException('Debug Rainbow Grades error', $this->error_messages);
    }

    public function error() {
        return $this->has_error;
    }

    public function getErrorMessages() {
        return $this->error_messages;
    }
}
