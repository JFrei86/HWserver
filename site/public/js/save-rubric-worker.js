import { ajaxSaveComponent, ajaxSaveMark, ajaxGetComponentRubric, ajaxSaveMarkOrder, getMarkFromMarkArray, tryResolveMarkSave, AJAX_USE_ASYNC} from "ta-grading-rubric";
/**
 * Saves the mark list to the server for a component and handles any conflicts.
 * Properties that are saved are: mark point values, mark titles, and mark order
 * @param {int} component_id
 * @return {Promise}
 */
function saveMarkList(component_id, old_mark_list, mark_list, gradeable_id) {
    return ajaxGetComponentRubric(gradeable_id, component_id)
        .then(function (component) {
            let domMarkList = mark_list[component_id];
            let serverMarkList = component.marks;
            let oldServerMarkList = old_mark_list[component_id];

            // associative array of associative arrays of marks with conflicts {<mark_id>: {domMark, serverMark, oldServerMark}, ...}
            let conflictMarks = {};

            let sequence = Promise.resolve();

            // For each DOM mark, try to save it
            domMarkList.forEach(function (domMark) {
                let serverMark = getMarkFromMarkArray(serverMarkList, domMark.id);
                let oldServerMark = getMarkFromMarkArray(oldServerMarkList, domMark.id);
                sequence = sequence
                    .then(function () {
                        return tryResolveMarkSave(gradeable_id, component_id, domMark, serverMark, oldServerMark);
                    })
                    .then(function (success) {
                        // success of false counts as conflict
                        if (success === false) {
                            conflictMarks[domMark.id] = {
                                domMark: domMark,
                                serverMark: serverMark,
                                oldServerMark: oldServerMark
                            };
                        }
                    });
            });

            return sequence
                .then(function () {
                    let markOrder = {};
                    domMarkList.forEach(function(mark) {
                        markOrder[mark.id] = mark.order;
                    });
                    // Finally, save the order
                    return ajaxSaveMarkOrder(gradeable_id, component_id, markOrder);
                });
        });
}

self.onmessage = function(e){
    self.postMessage("hi");
    let components = e.data[0];
    let old_mark_list = e.data[1];
    let mark_list = e.data[2];
    let edit_mode = e.data[3];
    let gradeable_id = e.data[4];
    //save mark
    components.foreach(function(component){
        saveMarkList(component[id], old_mark_list, mark_list, gradeable_id);
    });
    
}