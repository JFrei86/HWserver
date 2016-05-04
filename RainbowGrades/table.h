#include <vector>
#include <string>
#include <iostream>
#include <sstream>
#include <iomanip>

enum CELL_CONTENTS_STATUS { CELL_CONTENTS_VISIBLE, CELL_CONTENTS_HIDDEN, CELL_CONTENTS_NO_DETAILS };

class TableCell {
public:

  // CONSTRUCTORS
  TableCell(const std::string& c="ffcccc", const std::string& d="", const std::string& n="", 
            CELL_CONTENTS_STATUS v=CELL_CONTENTS_VISIBLE, const std::string& a="left" , int s=1, int r=0);
  TableCell(const std::string& c         , int                d   , const std::string& n="", 
            CELL_CONTENTS_STATUS v=CELL_CONTENTS_VISIBLE, const std::string& a="left" , int s=1, int r=0);
  TableCell(const std::string& c         , float              d   , const std::string& n="", 
            CELL_CONTENTS_STATUS v=CELL_CONTENTS_VISIBLE, const std::string& a="right", int s=1, int r=0);

  std::string color;
  std::string data;
  std::string note;
  std::string align;
  enum CELL_CONTENTS_STATUS visible;
  int span;
  int rotate;

  friend std::ostream& operator<<(std::ostream &ostr, const TableCell &c);

};



class Table {

public:

  Table() {
    cells = std::vector<std::vector<TableCell>>(1,std::vector<TableCell>(1));
  }

  void set(int r, int c, TableCell cell) {
    while(r >= numRows()) {
      cells.push_back(std::vector<TableCell>(numCols()));
    }
    while (c >= numCols()) {
      for (int x = 0; x < numRows(); x++) {
        cells[x].push_back(TableCell());
      }
    }
    cells[r][c] = cell;
  }

  void output(std::ostream& ostr,
              std::vector<int> which_students,
              std::vector<int> which_data,
              bool transpose=false,
              bool show_details=false,
              std::string last_update="") const;
  

  int numRows() const { return cells.size(); }
  int numCols() const { return cells[0].size(); }

private:
  std::vector<std::vector<TableCell>> cells;
};
