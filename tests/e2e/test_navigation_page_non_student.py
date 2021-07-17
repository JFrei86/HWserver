from .base_testcase import BaseTestCase
from collections import OrderedDict
from selenium.webdriver.common.by import By
from selenium.webdriver.common.keys import Keys
from selenium.webdriver.support.ui import WebDriverWait
from selenium.webdriver.support import expected_conditions as EC

class TestNavigationPageNonStudent(BaseTestCase):
    def __init__(self, testname):
        super().__init__(testname, log_in=False)

    #sections: list of tuples of size 2 (section name, section length)
    def validate_navigation_page_sections(self, sections):
        elements = self.driver.find_elements(By.CLASS_NAME, 'course-section-heading')
        self.assertEqual(len(sections), len(elements))
        index = 0
        for (section_name, section_size), element in zip(sections.items(), elements):
            self.assertEqual(section_name, element.get_attribute('id'))
            self.assertEqual(section_size, len(self.driver
                             .find_element(By.ID, section_name + '-section')
                             .find_elements(By.CLASS_NAME, "gradeable-row")), msg=section_name)

    def test_instructor(self):
        self.log_in(user_id="instructor", user_name="Quinn")
        self.click_class('sample')
        sections = OrderedDict()
        sections["future"] = 4
        sections["beta"] = 3
        sections["open"] = 3
        sections["closed"] = 3
        sections["items_being_graded"] = 9
        sections["graded"] = 10

        self.assertEqual(4, len(self.driver
                         .find_element(By.CLASS_NAME, 'gradeable-row')
                         .find_elements(By.CLASS_NAME, 'course-button')))

        gradeable_id = "future_no_tas_homework"
        
        self.validate_navigation_page_sections(sections)
        element = self.driver.find_element(By.XPATH, "//div[@id='"+gradeable_id+"']//a[contains(@href,'quick_link?action=open_ta_now')]")
        self.assertEqual("OPEN TO TAS NOW", element.find_element_by_class_name("subtitle").text)
        element.click()
        sections["future"] -= 1
        sections["beta"] += 1
        self.validate_navigation_page_sections(sections)
        element = self.driver.find_element(By.XPATH, "//div[@id='"+gradeable_id+"']//a[contains(@href,'quick_link?action=open_students_now')]")
        self.assertEqual("OPEN NOW", element.find_element_by_class_name("subtitle").text)
        element.click()
        sections["beta"] -= 1
        sections["open"] += 1
        self.validate_navigation_page_sections(sections)
        element = self.driver.find_element(By.XPATH, "//div[@id='"+gradeable_id+"']//a[contains(@onclick,'quick_link?action=close_submissions')]")
        self.assertEqual("CLOSE SUBMISSIONS NOW", element.find_element_by_class_name("subtitle").text)
        element.click()
        self.driver.find_element(By.XPATH, "//div[@id='close-submissions-form']//input[contains(@value,'Close Submissions')]").click()
        sections["open"] -= 1
        sections["closed"] += 1
        self.validate_navigation_page_sections(sections)
        element = self.driver.find_element(By.XPATH, "//div[@id='"+gradeable_id+"']//a[contains(@href,'quick_link?action=open_grading_now')]")
        self.assertEqual("OPEN TO GRADING NOW", element.find_element_by_class_name("subtitle").text)
        element.click()
        sections["closed"] -= 1
        sections["items_being_graded"] += 1
        self.validate_navigation_page_sections(sections)
        element = self.driver.find_element(By.XPATH, "//div[@id='"+gradeable_id+"']//a[contains(@href,'quick_link?action=release_grades_now')]")
        self.assertEqual("RELEASE GRADES NOW", element.find_element_by_class_name("subtitle").text)
        element.click()
        sections["items_being_graded"] -= 1
        sections["graded"] += 1
        self.validate_navigation_page_sections(sections)
        self.driver.find_element(By.XPATH, "//div[@id='"+gradeable_id+"']//a[contains(@href,'gradeable/"+gradeable_id+"/update')]").click()
        self.driver.find_element(By.XPATH, "//form[@id='gradeable-form']//div[@class='tab-bar-wrapper']//a[contains(text(), 'Dates')]").click()
        wait = WebDriverWait(self.driver, self.WAIT_TIME)
        element = self.driver.find_element(By.ID, "date_released")
        element.send_keys(Keys.CONTROL, "a")
        wait.until(lambda d: 'active' in element.get_attribute('class'))
        element.send_keys("9998-12-31 23:59:59")
        element.send_keys(Keys.ENTER)
        wait.until(EC.text_to_be_present_in_element((By.ID, "save_status"), "All Changes Saved"), message=self.driver.find_element(By.ID, "save_status").text)
        element = self.driver.find_element(By.ID, "date_grade_due")
        element.send_keys(Keys.CONTROL, "a")
        wait.until(lambda d: 'active' in element.get_attribute('class'))
        element.send_keys("9998-12-31 23:59:59")
        element.send_keys(Keys.ENTER)
        wait.until(EC.text_to_be_present_in_element((By.ID, "save_status"), "All Changes Saved"))
        element = self.driver.find_element(By.ID, "date_grade")
        element.send_keys(Keys.CONTROL, "a")
        wait.until(lambda d: 'active' in element.get_attribute('class'))
        element.send_keys("9997-12-31 23:59:59")
        element.send_keys(Keys.ENTER)
        wait.until(EC.text_to_be_present_in_element((By.ID, "save_status"), "All Changes Saved"))
        element = self.driver.find_element(By.ID, "date_due")
        element.send_keys(Keys.CONTROL, "a")
        wait.until(lambda d: 'active' in element.get_attribute('class'))
        element.send_keys("9996-12-31 23:59:59")
        element.send_keys(Keys.ENTER)
        wait.until(EC.text_to_be_present_in_element((By.ID, "save_status"), "All Changes Saved"))
        element = self.driver.find_element(By.ID, "date_submit")
        element.send_keys(Keys.CONTROL, "a")
        wait.until(lambda d: 'active' in element.get_attribute('class'))
        element.send_keys("9995-12-31 23:59:59")
        element.send_keys(Keys.ENTER)
        wait.until(EC.text_to_be_present_in_element((By.ID, "save_status"), "All Changes Saved"))
        element = self.driver.find_element(By.ID, "date_ta_view")
        element.send_keys(Keys.CONTROL, "a")
        wait.until(lambda d: 'active' in element.get_attribute('class'))
        element.send_keys("9994-12-31 23:59:59")
        element.send_keys(Keys.ENTER)
        wait.until(EC.text_to_be_present_in_element((By.ID, "save_status"), "All Changes Saved"))
        
        self.driver.find_element(By.XPATH, "//a[@id='nav-sidebar-submitty']").click()
        sections["graded"] -= 1
        sections["future"] += 1
        element = self.driver.find_element(By.XPATH, "//div[@id='"+gradeable_id+"']//a[contains(@href,'quick_link?action=open_ta_now')]")
        self.assertEqual("OPEN TO TAS NOW", element.find_element_by_class_name("subtitle").text)
        self.validate_navigation_page_sections(sections)

    def test_ta(self):
        self.log_in(user_id="ta", user_name="Jill")
        self.click_class('sample')
        elements = self.driver.find_elements(By.CLASS_NAME, 'course-section-heading')
        self.assertEqual(5, len(elements))
        self.assertEqual("beta", elements[0].get_attribute('id'))
        self.assertEqual(3, len(self.driver
                                .find_element(By.ID, 'beta-section')
                                .find_elements(By.CLASS_NAME, "gradeable-row")))
        self.assertEqual("open", elements[1].get_attribute('id'))
        self.assertEqual(3, len(self.driver
                                .find_element(By.ID, 'open-section')
                                .find_elements(By.CLASS_NAME, "gradeable-row")))
        self.assertEqual("closed", elements[2].get_attribute('id'))
        self.assertEqual(3, len(self.driver
                                .find_element(By.ID, 'closed-section')
                                .find_elements(By.CLASS_NAME, "gradeable-row")))
        self.assertEqual("items_being_graded", elements[3].get_attribute('id'))
        self.assertEqual(9, len(self.driver
                                .find_element(By.ID, 'items_being_graded-section')
                                .find_elements(By.CLASS_NAME, "gradeable-row")))
        self.assertEqual("graded", elements[4].get_attribute('id'))
        self.assertEqual(10, len(self.driver
                                .find_element(By.ID, 'graded-section')
                                .find_elements(By.CLASS_NAME, "gradeable-row")))

        self.assertEqual(3, len(self.driver.find_element(By.CLASS_NAME,
            'gradeable-row').find_elements(By.CLASS_NAME, 'course-button')))


if __name__ == "__main__":
    import unittest
    unittest.main()
