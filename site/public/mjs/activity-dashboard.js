/**
 * Sorts the table by the column n given by user
 * This function will toggle between sorting by ascending order and descending order
 * @param {int} n
 */
export function sortTable(n) {
    let i, dir;
    const table = document.getElementById('data-table');

    if ($(`#${n}`).children('i').hasClass('fa-angle-up')){
        $(`#${n}`).children('i').removeClass('fa-angle-up').addClass('fa-angle-down');
        dir = 'desc';
    }
    else {
        $(`#${n}`).children('i').removeClass('fa-angle-down').addClass('fa-angle-up');
        dir = 'asc';
    }

    for (i = 0; i < 10; i++){
        if (i != n && $(`#${i}`).children('i').hasClass('fa-angle-up')){
            $(`#${i}`).children('i').removeClass('fa-angle-up');
        }
        else if (i != n && $(`#${i}`).children('i').hasClass('fa-angle-down')){
            $(`#${i}`).children('i').removeClass('fa-angle-down');
        }
    }

    // Comparator used to compare 2 data entries for sorting
    const comparator = function (row1, row2) {
        // Check if they're equal
        if (!helper(row1[n].innerHTML, row2[n].innerHTML) && !helper(row2[n].innerHTML, row1[n].innerHTML)) {
            if (1 != n && helper(row1[1].innerHTML, row2[1].innerHTML, 1)) {
                return true;
            }
            return false;
        }
        // They are not equal
        // Then check for lesser or greater relationships
        if (dir == 'desc' && helper(row1[n].innerHTML, row2[n].innerHTML, n)) {
            return true;
        }
        else if (dir == 'asc' && helper(row2[n].innerHTML, row1[n].innerHTML, n)) {
            return true;
        }
        return false;
    };
    // if n == 0 or n == 8
    // returns true if x < y, digits < strings < empty strings
    const helper = function (x, y, i) {
        if (i == 0 || i == 8){
            const xIsDigit = /^\d+$/.test(x);
            const yIsDigit = /^\d+$/.test(y);
            if (xIsDigit && yIsDigit) {
                return Number(x) < Number(y);
            }
            else if (!xIsDigit ^ !yIsDigit){
                return xIsDigit;
            }
            else {
                if (x != '' && y == '') {
                    return true;
                }
                else if (x == '' && y != '') {
                    return false;
                }
                return x < y;
            }
        }
        else {
            // other columns
            return x < y;
        }
    };
    const merge = function(arr1, arr2) {
        const res = [];
        let i = 0, j = 0;
        while (i < arr1.length && j < arr2.length) {
            if (comparator(arr1[i].getElementsByTagName('TD'), arr2[j].getElementsByTagName('TD'))) {
                res.push(arr1[i]);
                i++;
            }
            else {
                res.push(arr2[j]);
                j++;
            }
        }
        while (i < arr1.length) {
            res.push(arr1[i]);
            i++;
        }
        while (j < arr2.length) {
            res.push(arr2[j]);
            j++;
        }
        return res;
    };

    const mergeSort = function (arr) {
        if (arr.length <= 1) {
            return arr;
        }

        const mid = Math.floor(arr.length/2);
        const left = mergeSort(arr.slice(0, mid));
        const right = mergeSort(arr.slice(mid));
        const res = merge(left,right);
        return res;
    };
    const rows = table.rows;
    const sorted = mergeSort(Array.prototype.slice.call(rows).slice(1));
    // inserting rows back into table to update the order
    for (i = 0; i < table.rows.length-1; i++) {
        rows[i+1].parentNode.insertBefore(sorted[i], rows[i+1]);
    }
}

export function applySettings(){
    var grad_acc = Date.parse(document.getElementById('gradeable_access_date').value);
    var grad_sub = Date.parse(document.getElementById('gradeable_submission_date').value);
    var forum_view = Date.parse(document.getElementById('forum_view_date').value);
    var forum_post = Date.parse(document.getElementById('forum_post_date').value);
    var num_poll = Date.parse(document.getElementById('num_poll_responses').value);
    var off_hours = Date.parse(document.getElementById('office_hours_queue_date').value);
    var data = JSON.parse(document.getElementById('data').getAttribute('data-original'));
    var table = document.getElementById("data-table");
    var rows = table.rows;
    for(var i = 0; i < data.length; i++){
        var s_grad_acc = data[i].gradeable_access;
        var s_grad_sub = data[i].gradeable_submission;
        var s_forum_view = data[i].forum_view;
        var s_forum_post = data[i].forum_post;
        var s_num_polls = data[i].num_poll_responses;
        var s_off_hours = data[i].office_hours_queue;
        var flag = false;

        if((!Number.isNaN(grad_acc) && s_grad_acc == null) || Date.parse(s_grad_acc) < grad_acc) flag = true;
        else if((!Number.isNaN(grad_sub) && s_grad_sub == null) || Date.parse(s_grad_sub) < grad_sub) flag = true;
        else if((!Number.isNaN(forum_view) && s_forum_view == null) || Date.parse(s_forum_view) < forum_view) flag = true;
        else if((!Number.isNaN(forum_post) && s_forum_post == null) || Date.parse(s_forum_post) < forum_post) flag = true;
        else if((!Number.isNaN(num_poll) && s_num_polls == null) || Date.parse(s_num_polls) < num_poll) flag = true;
        else if((!Number.isNaN(off_hours) && s_off_hours == null) || Date.parse(s_off_hours) < off_hours) flag = true;
        else {
            rows[i+1].getElementsByTagName("TD")[10].innerText = 'False'; 
            document.getElementById(data[i].user_id).style.backgroundColor= 'green';
        }
        if(flag){
            document.getElementById(data[i].user_id).style.backgroundColor = 'red';
            rows[i+1].getElementsByTagName("TD")[10].innerText = 'True';
        }

    }
    document.getElementById('office_hours_queue_date').value = '';
}

export function clearFields(){
    document.getElementById('gradeable_access_date').value = '';
    document.getElementById('gradeable_submission_date').value = '';
    document.getElementById('forum_view_date').value = '';
    document.getElementById('forum_post_date').value = '';
    document.getElementById('num_poll_responses').value = '';
    document.getElementById('office_hours_queue_date').value = '';
    applySettings();
    var table = document.getElementById("data-table");
    var data = JSON.parse(document.getElementById('data').getAttribute('data-original'));
    var rows = table.rows;
    for(var i = 0; i < data.length; i++){
        rows[i+1].getElementsByTagName("TD")[10].innerText = ''; 
        document.getElementById(data[i].user_id).style.backgroundColor= '';
    }
}

export function init(){
    sortTable(0);
    document.getElementById("0").addEventListener("click", () => sortTable(0));
    document.getElementById("1").addEventListener("click", () => sortTable(1));
    document.getElementById("2").addEventListener("click", () => sortTable(2));
    document.getElementById("3").addEventListener("click", () => sortTable(3));
    document.getElementById("4").addEventListener("click", () => sortTable(4));
    document.getElementById("5").addEventListener("click", () => sortTable(5));
    document.getElementById("6").addEventListener("click", () => sortTable(6));
    document.getElementById("7").addEventListener("click", () => sortTable(7));
    document.getElementById("8").addEventListener("click", () => sortTable(8));
    document.getElementById("9").addEventListener("click", () => sortTable(9));
    document.getElementById("10").addEventListener("click", () => sortTable(10));
    
    document.getElementById("clear-btn").addEventListener("click", () => clearFields());
    document.getElementById("apply-btn").addEventListener("click", () => applySettings());
}

document.addEventListener('DOMContentLoaded', () => init());