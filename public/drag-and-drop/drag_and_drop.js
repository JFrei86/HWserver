/*
https://developer.mozilla.org/en-US/docs/Using_files_from_web_applications
https://developer.mozilla.org/en-US/docs/Web/API/FormData/Using_FormData_Objects
https://developer.mozilla.org/en-US/docs/Web/API/XMLHttpRequest/Using_XMLHttpRequest#Submitting_forms_and_uploading_files
https://developer.mozilla.org/en-US/docs/Web/Guide/HTML/Dragging_and_Dropping_Multiple_Items
https://www.sitepoint.com/html5-file-drag-and-drop/
https://www.sitepoint.com/html5-ajax-file-upload/
http://www.html5rocks.com/en/tutorials/file/dndfiles/
*/

var file_array = [];
var previous_files = [];
var changed = false;
function createArray(num_parts){
	if(file_array.length == 0){
		for(var i=0; i<num_parts; i++){
			file_array.push([]);
			previous_files.push([]);
		}
	}
}

function readPrevious(filename, part){
	previous_files[part-1].push(filename);
}

// open a file browser if clicked on drop zone
function clicked_on_box(e){
  document.getElementById("input_file" + get_part_number(e)).click();
  e.stopPropagation();
}

function draghandle(e){
	e.preventDefault();
	e.stopPropagation();
	document.getElementById("upload" + get_part_number(e)).style.opacity = (e.type == "dragenter" || e.type == "dragover") ? .5 : "";
}

function drop(e){
	draghandle(e);
	var filestream= e.dataTransfer.files;
	var part = get_part_number(e);
	for(var i=0; i<filestream.length; i++){
		addFileWithCheck(filestream[i], part); // check for folders
	}
}

function get_part_number(e){
	if(e.target.id.substring(0, 6) == "upload"){
		return e.target.id.substring(6);
	}
	else{
		return e.target.parentNode.id.substring(6);
	}
}

// copy files selected from the dialog box
function addFilesFromInput(part){
	var filestream = document.getElementById("input_file" + part).files;
	for(var i=0; i<filestream.length; i++){
		addFile(filestream[i], part); // folders will not be selected in file browser, no need for check
	}
}

// Check for duplicate file names. This function returns an array.
// First element:
// 1 - a file with the same name found in previous submission
// 0 - a file with the same name already selected for this version
// -1 - does not exist files with the same name
// Second element: index of the file with the same name (if found)
function fileExists(file, part){
	for(var i=0; i<previous_files[part-1].length; i++){
		if(previous_files[part-1][i] == file.name){
			return [1, i];
		}
	}
	for(var i=0; i<file_array[part-1].length; i++){
		if(file_array[part-1][i].name == file.name){
			return [0, i];
		}
	}
	return [-1];
}

function addFileWithCheck(file, part){
	if(!file.type || file.size%4096 == 0){
		var reader = new FileReader();
		reader.onload = notFolder(file, part);
		reader.onerror = isFolder(file);
		reader.readAsBinaryString(file);
	}
	else{
		addFile(file, part);
	}
}

function notFolder(file, part){
	return function(e){ addFile(file, part); }
}

function isFolder(file){
	return function(e){ alert("Upload failed: " + file.name + " might be a folder."); }
}

function addFile(file, part){
	var i = fileExists(file, part);
	if( i[0] == -1 ){	// file does not exist
		file_array[part-1].push(file);
		addLabel(file.name, (file.size/1024).toFixed(2), part, false);
	}
	else if(i[0] == 0){	// file already selected
		if(confirm("Note: " + file_array[part-1][i[1]].name + "is already selected. Do you want to replace it?")){
			file_array[part-1].splice(i[1], 1, file);
			removeLabel(file.name, part);
			addLabel(file.name, (file.size/1024).toFixed(2), part, false);
		}
	}
	else{	// file in previous submission
		if(confirm("Note: " + previous_files[part-1][i[1]] + "was in your previous submission. Do you want to replace it?")){
			file_array[part-1].push(file);
			previous_files[part-1].splice(i[1], 1);
			removeLabel(file.name, part);
			addLabel(file.name, (file.size/1024).toFixed(2), part, false);
			changed = true;
		}
	}
}

// remove all files uploaded
function deleteFiles(part){
	if(file_array.length != 0){
		file_array[part-1] = [];
	}
	if(previous_files.length != 0){
		previous_files[part-1] = [];
	}
	var dropzone = document.getElementById("upload" + part);
	var labels = dropzone.getElementsByClassName("mylabel");
	while(labels[0]){
		dropzone.removeChild(labels[0]);
	}
	changed = true;
}

function deleteSingleFile(filename, part, previous){
	// Remove files from previous submission
	if(previous){
		for(var i=0; i<previous_files[part-1].length; i++){
			if(previous_files[part-1][i] == filename){
				previous_files[part-1].splice(i, 1);
				changed = true;
				break;
			}
		}
	}
	// Remove files uploaded for submission
	else{
		for(var i=0; i<file_array[part-1].length; i++){
			if(file_array[part-1][i].name == filename){
				file_array[part-1].splice(i, 1);
				break;
			}
		}
	}
}

function removeLabel(filename, part){
	var dropzone = document.getElementById("upload" + part);
	var labels = dropzone.getElementsByClassName("mylabel");
	for(var i=0 ; i<labels.length; i++){
		if(labels[i].innerHTML.substring(0, filename.length) == filename){
			dropzone.removeChild(labels[i]);
			break;
		}
	}
}

function addLabel(filename, filesize, part, previous){
	// create element
	var tmp = document.createElement('label');
	tmp.setAttribute("class", "mylabel");
	tmp.innerHTML =  filename + " " + filesize + "kb" + "<br>";
	// styling
	tmp.onmouseover = function(e){
		e.stopPropagation();
		this.style.color = "#FF3933";
		this.style.background = "lightgrey";
	}
	tmp.onmouseout = function(e){
		e.stopPropagation();
		this.style.color = "black";
		this.style.background = "";
	}
	// remove file and label on click
	tmp.onclick = function(e){
		e.stopPropagation();
		this.parentNode.removeChild(this);
		deleteSingleFile(filename, part, previous);
	}
	// add to parent div
	var dropzone = document.getElementById("upload" + part);
	var deletebutton = document.getElementById("delete" + part);
	dropzone.appendChild(tmp);
	dropzone.insertBefore(tmp, deletebutton);
}

function validSubmission(){
	// check if new files added
	for(var i=0; i < file_array.length; i++){
		if(file_array[i].length != 0){
			return true;
		}
	}
	// check if files from previous submission changed
	if(changed){
		// check if previous submission files are emptied
		for(var i=0; i < previous_files.length; i++){
			if(previous_files[i] != 0){
				return true;
			}
		}
	}
	return false;
}

function submit(url, csrf_token, svn_checkout, loc){
	// Check if new submission
	if(!validSubmission()){
		alert("Not a new submission.");
		window.location.reload();
		return;
	}
	var files_to_upload = new FormData();

	files_to_upload.append('csrf_token', csrf_token);
	files_to_upload.append('svn_checkout', svn_checkout);

	// Files uploaded
	for(var i=0; i<file_array.length; i++){
		for(var j=0; j<file_array[i].length; j++){
			files_to_upload.append('files' + (i+1) + '[]', file_array[i][j]);
			// files_to_upload.append('files[' + (i+1) + '][]', file_array[i][j]);
		}
	}
	files_to_upload.append('previous_files', JSON.stringify(previous_files));

	// xhr
	var xhr = new XMLHttpRequest();
	xhr.open("POST", url, true);
    xhr.onreadystatechange = function() {
        if (xhr.readyState == 4 && xhr.status == 200) {
            // Handle response.
            // alert(xhr.responseText);
            // window.location.reload();
            window.location.href = loc;
        }
    };
	xhr.send(files_to_upload);
	/*
	//
	jQuery.ajax(url, {
		data: files_to_upload,
		method: "post"
	})
	.complete(function() {

	})
	.error(function() {

	})
*/
}
