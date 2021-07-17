/**
 * The number of decimal places to show to the user
 * @type {int}
 */
DECIMAL_PRECISION = 3;

var itempool_items = {};

/**
 * Asynchronously load all of the templates
 * @return {Promise}
 */
function loadTemplates() {
    let templates = [
        {id: 'GradingGradeable', href: '/templates/grading/GradingGradeable.twig'},
        {id: 'PeerGradeable', href: '/templates/grading/PeerGradeable.twig'},
        {id: 'EditGradeable', href: '/templates/grading/EditGradeable.twig'},
        {id: 'Gradeable', href: "/templates/grading/Gradeable.twig"},
        {id: 'GradingComponent', href: "/templates/grading/GradingComponent.twig"},
        {id: 'GradingComponentHeader', href: "/templates/grading/GradingComponentHeader.twig"},
        {id: 'EditComponent', href: '/templates/grading/EditComponent.twig'},
        {id: 'EditComponentHeader', href: '/templates/grading/EditComponentHeader.twig'},
        {id: 'Component', href: "/templates/grading/Component.twig"},
        {id: 'Mark', href: "/templates/grading/Mark.twig"},
        {id: 'OverallComment', href: "/templates/grading/OverallComment.twig"},
        {id: 'TotalScoreBox', href: "/templates/grading/TotalScoreBox.twig"},
        {id: 'TotalPeerScoreBox', href: "/templates/grading/TotalPeerScoreBox.twig"},
        {id: 'ConflictMarks', href: "/templates/grading/ConflictMarks.twig"},
        {id: 'RubricTotalBox', href: "/templates/grading/RubricTotalBox.twig"},
    ];
    let promises = [];
    templates.forEach(function (template) {
        promises.push(new Promise(function (resolve, reject) {
            Twig.twig({
                id: template.id,
                href: template.href,
                allowInlineIncludes: true,
                async: true,
                error: function () {
                    reject();
                },
                load: function () {
                    resolve();
                }
            });
        }));
    });
    return Promise.all(promises);
}

/**
 * Calculates the score of a graded component
 * @param {Object} component
 * @param {Object} graded_component
 * @return {number}
 */
function calculateGradedComponentTotalScore(component, graded_component) {
    let markCount = 0;

    // Calculate the total
    let total = component.default;
    if (graded_component.custom_mark_selected) {
        total += graded_component.custom_mark_selected && !isNaN(graded_component.score) ? graded_component.score : 0.0;
        markCount++;
    }
    component.marks.forEach(function (mark) {
        if (graded_component.mark_ids.includes(mark.id)) {
            total += mark.points;
            markCount++;
        }
    });

    // If there were no marks earned, then there is no 'total'
    if (markCount === 0) {
        return undefined;
    }

    // Then clamp it in range
    return Math.min(component.upper_clamp, Math.max(total, component.lower_clamp));
}

function prepGradedComponent(component, graded_component) {
    if (graded_component === undefined) {
        return undefined;
    }

    // The custom mark selected property isn't set
    if (graded_component.custom_mark_selected === undefined) {
        graded_component.custom_mark_selected = graded_component.comment !== '';
    }

    // Calculate the total score
    if (graded_component.total_score === undefined) {
        graded_component.total_score = calculateGradedComponentTotalScore(component, graded_component);
    }

    // Unset blank properties
    if (graded_component.grader_id === '') {
        graded_component.grader_id = undefined;
    }

    // Check if verifier exists
    if (graded_component.verifier_id === '') {
        graded_component.verifier_id = undefined;
    }

    return graded_component;
}
/**
 * Asynchronously render a gradeable using the passed data
 * Note: Call 'loadTemplates' first
 * @param {string} grader_id
 * @param {Object} gradeable
 * @param {Object} graded_gradeable
 * @param {boolean} grading_disabled
 * @param {boolean} canVerifyGraders
 * @param {int} displayVersion
 * @returns {Promise<string>} the html for the graded gradeable
 */

function renderGradingGradeable(grader_id, gradeable, graded_gradeable, grading_disabled, canVerifyGraders, displayVersion) {
    if (graded_gradeable.graded_components === undefined || graded_gradeable.graded_components === null) {
        graded_gradeable.graded_components = {};
    }
    // Calculate the total scores
    gradeable.components.forEach(function (component) {
        graded_gradeable.graded_components[component.id]
            = prepGradedComponent(component, graded_gradeable.graded_components[component.id]);
    });
    if (graded_gradeable.itempool_items !== undefined) {
        itempool_items = {...itempool_items, ...graded_gradeable.itempool_items};
    }

    // TODO: i don't think this is async
    return Twig.twig({ref: "GradingGradeable"}).render({
        'gradeable': gradeable,
        'graded_gradeable': graded_gradeable,
        'edit_marks_enabled': false,
        'grading_disabled': grading_disabled,
        'decimal_precision': DECIMAL_PRECISION,
        'can_verify_graders': canVerifyGraders,
        'grader_id': grader_id,
        'display_version': displayVersion,
    });
}

/**
 * Asynchronously render a peer gradeable using the passed data
 * Note: Call 'loadTemplates' first
 * @param {string} grader_id
 * @param {Object} gradeable
 * @param {Object} graded_gradeable
 * @param {boolean} grading_disabled
 * @param {boolean} canVerifyGraders
 * @param {int} displayVersion
 * @returns {Promise<string>} the html for the peer gradeable
 */

function renderPeerGradeable(grader_id, gradeable, graded_gradeable, grading_disabled, canVerifyGraders, displayVersion) {
    if (graded_gradeable.graded_components === undefined) {
        graded_gradeable.graded_components = {};
    }

    let peer_details = {};

    // Group together some useful data for rendering:
    gradeable.components.forEach(function(component) {
        // The peer details for a specific component (who has graded it and what marks have they chosen.)
        peer_details[component.id] = {
            "graders" : [],
            "marks_assigned" : {}
        }
        graded_gradeable.graded_components[component.id].forEach(function(graded_component){
            peer_details[component.id]["graders"].push(graded_component.grader_id);
            peer_details[component.id]["marks_assigned"][graded_component.grader_id] = graded_component.mark_ids;
        });
    });
    // TODO: i don't think this is async
    return Twig.twig({ref: "PeerGradeable"}).render({
        'gradeable': gradeable,
        'graded_gradeable': graded_gradeable,
        'edit_marks_enabled': false,
        'grading_disabled': grading_disabled,
        'decimal_precision': DECIMAL_PRECISION,
        'can_verify_graders': canVerifyGraders,
        'grader_id': grader_id,
        'display_version': displayVersion,
        'peer_details' : peer_details
    });
}

/**
 * Asynchronously render a component using the passed data
 * @param {string} grader_id
 * @param {Object} component
 * @param {Object} graded_component
 * @param {boolean} grading_disabled
 * @param {boolean} canVerifyGraders
 * @param {number} precision
 * @param {boolean} editable True to render with edit mode enabled
 * @param {boolean} showMarkList True to display the mark list unhidden
 * @param {boolean} componentVersionConflict
 * @param {boolean} is_student False if the grader is a TA, True if peer grader
 * @returns {Promise<string>} the html for the graded component
 */


function renderGradingComponent(grader_id, component, graded_component, grading_disabled, canVerifyGraders, precision, editable, showMarkList, componentVersionConflict, is_student, taGradingPeer, allowCustomMarks) {
    return new Promise(function (resolve, reject) {
        // Make sure we prep the graded component before rendering
        graded_component = prepGradedComponent(component, graded_component);
        if (is_student) {
            component.ta_comment = "";
        } else {
            component.student_comment = "";
        }
        // TODO: i don't think this is async
        resolve(Twig.twig({ref: "GradingComponent"}).render({
            'component': component,
            'graded_component': graded_component,
            'precision': precision,
            'edit_marks_enabled': editable,
            'show_mark_list': showMarkList,
            'grading_disabled': grading_disabled,
            'decimal_precision': DECIMAL_PRECISION,
            'can_verify_graders': canVerifyGraders,
            'grader_id': grader_id,
            'peer_component' : component.peer,
            'allow_custom_marks' : allowCustomMarks,
            'itempool_id': itempool_items.hasOwnProperty(component.id) ? itempool_items[component.id] : '',
            'ta_grading_peer': taGradingPeer
        }));
    });
}


/**
 * Asynchronously render a component header using the passed data
 * @param {string} grader_id
 * @param {Object} component
 * @param {Object} graded_component
 * @param {boolean} grading_disabled
 * @param {boolean} canVerifyGraders
 * @param {boolean} showMarkList True to style the header like the component is open
 * @param {boolean} componentVersionConflict
 * @returns {Promise<string>} the html for the graded component
 */
function renderGradingComponentHeader(grader_id, component, graded_component, grading_disabled, canVerifyGraders, showMarkList, componentVersionConflict) {
    return new Promise(function (resolve, reject) {
        // Make sure we prep the graded component before rendering
        graded_component = prepGradedComponent(component, graded_component);
        // TODO: i don't think this is async
        resolve(Twig.twig({ref: "GradingComponentHeader"}).render({
            'component': component,
            'graded_component': graded_component,
            'show_verify_grader': canVerifyGraders && showVerifyComponent(graded_component, grader_id),
            'show_mark_list': showMarkList,
            'grading_disabled': grading_disabled,
            'decimal_precision': DECIMAL_PRECISION,
            'component_version_conflict' : componentVersionConflict,
        }));
    });
}

/**
 * Asynchronously renders a gradeable using the passed data
 * Note: Call 'loadTemplates' first
 * @param gradeable
 * @returns {Promise<string>} the html for the gradeable
 */
function renderInstructorEditGradeable(gradeable, itempool_available, itempool_options) {
    return Twig.twig({ref: "EditGradeable"}).render({
        'gradeable': gradeable,
        'edit_marks_enabled': true,
        'itempool_available': itempool_available,
        'itempool_options': itempool_options,
        'decimal_precision': DECIMAL_PRECISION,
        'export_components_url': buildCourseUrl(['gradeable', gradeable.id, 'components', 'export'])
    });
}

/**
 * Asynchronously render a component using the passed data
 * @param {Object} component
 * @param {number} precision
 * @param {boolean} showMarkList True to display the mark list unhidden
 * @returns {Promise} the html for the component
 */
function renderEditComponent(component, precision, showMarkList) {

    return new Promise(function (resolve, reject) {
        // TODO: I don't think this is async
        resolve(Twig.twig({ref: "EditComponent"}).render({
            'component': component,
            'precision': precision,
            'show_mark_list': showMarkList,
            'edit_marks_enabled': true,
            'itempool_available': isItempoolAvailable(),
            'itempool_options': getItempoolOptions(),
            'decimal_precision': DECIMAL_PRECISION,
            'peer_component' : component.peer_component,
        }));
    });
}


/**
 * Asynchronously render a component header using the passed data
 * @param {Object} component
 * @param {boolean} showMarkList True to style the header like the component is open
 * @returns {Promise} the html for the component
 */
function renderEditComponentHeader(component, showMarkList) {
    return new Promise(function (resolve, reject) {
        // TODO: i don't think this is async
        resolve(Twig.twig({ref: "EditComponentHeader"}).render({
            'component': component,
            'extra_credit_points': component.upper_clamp - component.max_value,
            'penalty_points': 0 - component.lower_clamp,
            'show_mark_list': showMarkList,
            'decimal_precision': DECIMAL_PRECISION
        }));
    });
}

/**
 * Asynchronously renders the overall component using passed data
 * @param {string} comment
 * @param {boolean} editable If the comment should be open for edits, or closed
 * @return {Promise<string>}
 */
function renderOverallComment(comment, editable) {
    return new Promise(function (resolve, reject) {
        // TODO: i don't think this is async
        resolve(Twig.twig({ref: "OverallComment"}).render({
            'overall_comment': comment,
            'editable': editable,
            'grading_disabled': false
        }));
    });
}

/**
 * Asynchronously renders the total scores box
 * @param {Object} scores
 * @return {Promise<string>}
 */
function renderTotalScoreBox(scores) {
    return new Promise(function (resolve, reject) {
        scores.decimal_precision = DECIMAL_PRECISION;
        // TODO: i don't think this is async
        resolve(Twig.twig({ref: "TotalScoreBox"}).render(scores));
    });
}

/**
 * Asynchronously renders the rubric total box
 * @param {Object} scores
 * @returns {Promise<string>}
 */
function renderRubricTotalBox(scores) {
    return new Promise(function (resolve, reject) {
        scores.decimal_precision = DECIMAL_PRECISION;
        // TODO: i don't think this is async
        resolve(Twig.twig({ref: "RubricTotalBox"}).render(scores));
    });
}

/**
 *
 * @param conflict_marks
 * @return {Promise<string>}
 */
function renderConflictMarks(conflict_marks) {
    return new Promise(function (resolve, reject) {
        // TODO: i don't think this is async
        resolve(Twig.twig({ref: "ConflictMarks"}).render({
            conflict_marks: conflict_marks,
            decimal_precision: DECIMAL_PRECISION
        }));
    })
}

/**
 * 
 * @return {boolean}
 */
function isStudentGrader(){
    return $("#student-grader").attr("is-student-grader"); 
}
