from .base_testcase import BaseTestCase
from selenium.webdriver.common.by import By


class TestNavigationPageStudent(BaseTestCase):
    def test_navigation_page(self):
        self.click_class('sample')
        elements = self.driver.find_elements(By.CLASS_NAME, 'course-section-heading')
        self.assertEqual(4, len(elements))
        self.assertEqual("open", elements[0].get_attribute('id'))
        self.assertEqual(4, len(self.driver
                                .find_element(By.ID, 'open-section')
                                .find_elements(By.CLASS_NAME, "gradeable-row")))
        self.assertEqual("closed", elements[1].get_attribute('id'))
        self.assertEqual(3, len(self.driver
                                .find_element(By.ID, 'closed-section')
                                .find_elements(By.CLASS_NAME, "gradeable-row")))
        self.assertEqual("items_being_graded", elements[2].get_attribute('id'))
        self.assertEqual(5, len(self.driver
                                .find_element(By.ID, 'items_being_graded-section')
                                .find_elements(By.CLASS_NAME, "gradeable-row")))
        self.assertEqual("graded", elements[3].get_attribute('id'))
        self.assertEqual(8, len(self.driver
                                .find_element(By.ID, 'graded-section')
                                .find_elements(By.CLASS_NAME, "gradeable-row")))

        self.assertEqual(2, len(self.driver.find_element(By.CLASS_NAME,
            'gradeable-row').find_elements(By.CLASS_NAME, 'course-button')))


if __name__ == "__main__":
    import unittest
    unittest.main()
