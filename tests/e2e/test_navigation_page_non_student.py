from .base_testcase import BaseTestCase
from selenium.webdriver.common.by import By


class TestNavigationPageNonStudent(BaseTestCase):
    def __init__(self, testname):
        super().__init__(testname, log_in=False)

    def test_instructor(self):
        self.log_in(user_id="instructor", user_name="Quinn")
        self.click_class('sample')
        elements = self.driver.find_elements(By.CLASS_NAME, 'course-section-heading')
        self.assertEqual(6, len(elements))
        self.assertEqual("future", elements[0].get_attribute('id'))
        self.assertEqual(4, len(self.driver
                         .find_element(By.ID, 'future-section')
                         .find_elements(By.CLASS_NAME, "gradeable-row")))
        self.assertEqual("beta", elements[1].get_attribute('id'))
        self.assertEqual(3, len(self.driver
                                 .find_element(By.ID, 'beta-section')
                                 .find_elements(By.CLASS_NAME, 'gradeable-row')))
        self.assertEqual("open", elements[2].get_attribute('id'))
        self.assertEqual(3, len(self.driver
                                .find_element(By.ID, 'open-section')
                                .find_elements(By.CLASS_NAME, "gradeable-row")))
        self.assertEqual("closed", elements[3].get_attribute('id'))
        self.assertEqual(2, len(self.driver
                         .find_element(By.ID, 'closed-section')
                         .find_elements(By.CLASS_NAME, "gradeable-row")))
        self.assertEqual("items_being_graded", elements[4].get_attribute('id'))
        self.assertEqual(10, len(self.driver
                         .find_element(By.ID, 'items_being_graded-section')
                         .find_elements(By.CLASS_NAME, "gradeable-row")))
        self.assertEqual("graded", elements[5].get_attribute('id'))
        self.assertEqual(10, len(self.driver
                         .find_element(By.ID, 'graded-section')
                         .find_elements(By.CLASS_NAME, "gradeable-row")))
        self.assertEqual(4, len(self.driver
                         .find_element(By.CLASS_NAME, 'gradeable-row')
                         .find_elements(By.CLASS_NAME, 'course-button')))

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
        self.assertEqual(2, len(self.driver
                                .find_element(By.ID, 'closed-section')
                                .find_elements(By.CLASS_NAME, "gradeable-row")))
        self.assertEqual("items_being_graded", elements[3].get_attribute('id'))
        self.assertEqual(10, len(self.driver
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
