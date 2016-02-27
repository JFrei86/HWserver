#include <fstream>
#include <map>
#include <iomanip>


#include "student.h"
#include "constants_and_globals.h"

//==========================================================================

class ZoneInfo {
public:
  ZoneInfo() { max = 0; count = 0; }
  std::string building;
  std::string room;
  std::string zone;
  int max;
  int count;
};


// random generator function:
int myrandomzone (int i) { return std::rand()%i;}

//==========================================================================


void LoadExamSeatingFile(const std::string &zone_counts_filename, const std::string &zone_assignments_filename, std::vector<Student*> &students) {
  std::cout << "zone counts filename " << zone_counts_filename << std::endl;
  std::cout << "zone assignments filename " << zone_assignments_filename << std::endl;



  // ============================================================
  // read in the desired zone counts

  std::map<std::string,ZoneInfo> zones;
  std::ifstream istr_zone_counts(zone_counts_filename.c_str());
  assert (istr_zone_counts.good());
  
  int total_seats = 0;
  ZoneInfo zi;
  while (istr_zone_counts >> zi.zone >> zi.building >> zi.room >> zi.max) {
    zi.count=0;
    
    if (zones.find(zi.zone) != zones.end()) {
      std::cerr << "\nERROR: duplicate zone " << zi.zone << " in " << zone_counts_filename << std::endl;
      exit(0);
    }
    
    assert (zi.max >= 0);
    total_seats += zi.max;
    zones.insert(std::make_pair(zi.zone,zi));
  }
  std::cout << "TOTAL SEATS FOR EXAM " << total_seats << std::endl;

  // ============================================================
  // read in any existing assignments...
  int existing_assignments = 0;
  {
    std::ifstream istr_zone_assignments(zone_assignments_filename.c_str());
    if (istr_zone_assignments.good()) {
      std::string line;
      while (getline(istr_zone_assignments,line)) {
        std::stringstream ss(line.c_str());
        std::string token,last,first,rcs,building,room,zone,time;
        ss >> last >> first >> rcs >> building >> room >> zone >> time;
        if (last == "") break;
        Student *s = GetStudent(students,rcs);
        if (s == NULL) {
          std::cout << "seating assignment...  couldn't find this userid " << rcs << std::endl;
        }
        assert (s != NULL);
        if (zone != "") {
          std::map<std::string,ZoneInfo>::iterator itr = zones.find(zone);
          if (itr == zones.end()) {
            std::cerr << "ERROR! this zone '" << zone << "' assigned to '" << s->getUserName() << "'does not exist!" << std::endl;
            exit(1);
          }
          if (itr->second.max <= itr->second.count) {
            std::cerr << "ERROR! this zone '" << zone << "' is full (max:" << itr->second.max << ")" << std::endl;
            exit(1);
          }
          assert (itr->second.building == building);
          assert (itr->second.room == room);
          itr->second.count++;
          existing_assignments++;
          s->setExamRoom(building+std::string(" ")+room);
          s->setExamZone(zone);
          if (time != "") {
            s->setExamTime(time);
          } else {
            s->setExamTime(GLOBAL_EXAM_TIME);
          }
        }
      }
    }
  }
  std::cout << "EXISTING ASSIGNMENTS  " << existing_assignments << std::endl;

  // ============================================================
  // make a vector of available seats

  // FIXME: this belongs once, at start of program
  std::srand ( unsigned ( std::time(0) ) );
  
  std::vector<std::string> randomized_available;
  for (std::map<std::string,ZoneInfo>::iterator itr = zones.begin();
       itr != zones.end(); itr++) {
    assert (itr->second.count <= itr->second.max);
    for (int i = itr->second.count; i < itr->second.max; i++) {
      randomized_available.push_back(itr->first);
    }
  }
  std::cout << "AVAILABLE SEATS " << randomized_available.size() << std::endl;
  std::random_shuffle ( randomized_available.begin(), randomized_available.end(), myrandomzone );

  // ============================================================
  // do the assignments!

  int not_reg = 0;
  int no_grades = 0;
  int new_zone_assign = 0;
  int already_zoned = 0;
  int next_za = 0;

  for (unsigned int i = 0; i < students.size(); i++) {

    Student* &s = students[i];

    if (s->getExamRoom() != "") {
      already_zoned++;
    } else if (!validSection(s->getSection())) {
      not_reg++;
    } else if (s->overall() < GLOBAL_MIN_OVERALL_FOR_ZONE_ASSIGNMENT) {
      no_grades++;
    } else {
      //      std::cout << "ERROR assigning zone for " << s->getUserName() << std::endl;
      assert (next_za < int(randomized_available.size()));
      ZoneInfo &zi = zones.find(randomized_available[next_za])->second;
      s->setExamRoom(zi.building+std::string(" ")+zi.room);
      s->setExamZone(zi.zone);
      next_za++;
      new_zone_assign++;
      zi.count++;
    }
  }
  
  std::cout << "new zone assignments             " << new_zone_assign << std::endl;
  std::cout << "no grades (not assigning a zone) " << no_grades << std::endl;
  std::cout << "not registered in valid section  " << not_reg << std::endl;

  assert (new_zone_assign <= int(randomized_available.size()));


  // ============================================================
  // write out the assignments!

  if (new_zone_assign > 0) {

    std::ofstream ostr_zone_assignments(zone_assignments_filename.c_str());
    assert (ostr_zone_assignments.good());
    
    for (unsigned int i = 0; i < students.size(); i++) {
      
      Student* &s = students[i];
      
      if (s->getLastName() == "") continue;

      ostr_zone_assignments << std::setw(20) << std::left << s->getLastName()  << " ";
      ostr_zone_assignments << std::setw(15) << std::left << s->getFirstName() << " ";
      ostr_zone_assignments << std::setw(12) << std::left << s->getUserName()  << " ";

      ostr_zone_assignments << std::setw(10) << std::left << s->getExamRoom()  << " ";
      ostr_zone_assignments << std::setw(10) << std::left << s->getExamZone()  << " ";
      ostr_zone_assignments << std::setw(10) << std::left << s->getExamTime();
      
      ostr_zone_assignments << std::endl;

    }

  }

  // ============================================================
  // data for preparing exams


  int total_assignments = 0;
  for (std::map<std::string,ZoneInfo>::iterator itr = zones.begin();
       itr != zones.end(); itr++) {

    std::cout << "ZONE " << std::left  << std::setw(4) << itr->first 
              << " "     << std::left  << std::setw(10) << itr->second.building << "  " 
              << " "     << std::left  << std::setw(4) << itr->second.room << "  " 
              << " "     << std::right << std::setw(4) << itr->second.count << std::endl;

    total_assignments += itr->second.count;
  }
  std::cout << "TOTAL " << total_assignments << std::endl;


}
