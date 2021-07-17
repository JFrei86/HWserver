import re
import tzlocal
from datetime import datetime, timedelta


def get_timezone():
    """
    Grab the system timezone, should generally only be used when we don't have any
    further information on what timezone we should be using

    :return:
    :rtype: pytz.tzinfo.DstTzInfo
    """
    # noinspection PyUnresolvedReferences
    return tzlocal.get_localzone()


def get_current_time():
    """
    Get the current time, in the timezone set on the server
    :return:
    """
    return datetime.now(get_timezone())


def write_submitty_date(d=None, milliseconds=False):
    """
    Converts a datetime object to a string with a timezone. If the datetime object
    does not have a timezone, it'll use the server's timezone.

    :param d: datetime object you want to convert to string
    :param milliseconds: add milliseconds to returned string
    :return:
    """
    if d is None:
        d = get_current_time()
    if not isinstance(d, datetime):
        raise TypeError(
            f"Invalid type. Expected datetime or datetime string, got {type(d)}."
        )
    if d.tzinfo is None:
        d = get_timezone().localize(d)

    if milliseconds:
        mlsec = d.strftime("%f")[0:3]
        answer = d.strftime(f"%Y-%m-%d %H:%M:%S.{mlsec}%z")
    else:
        answer = d.strftime("%Y-%m-%d %H:%M:%S%z")
    return answer


def read_submitty_date(s):
    """
    Convert a string (with a timezone) to a date time object with a timezone
    :param s:
    :return:

    FIXME: We should not be raising a SystemExit within a utility function
    """
    words = s.split()
    if len(words) < 2 or len(words) > 3:
        raise SystemExit("ERROR: unexpected date format %s" % s)
    thedatetime = str(words[0] + ' ' + words[1])
    try:
        # hoping to find timezone -0400
        with_timezone = datetime.strptime(thedatetime, '%Y-%m-%d %H:%M:%S%z')
    except ValueError:
        try:
            # hoping to find no timezone
            without_timezone = datetime.strptime(thedatetime, '%Y-%m-%d %H:%M:%S')
            my_timezone = get_timezone()
            with_timezone = my_timezone.localize(without_timezone)
        except ValueError:
            try:
                # hoping to find timezone -04
                thedatetime = thedatetime+"00"
                print("dateutils read_submitty_date -- added '00' to ", thedatetime)
                with_timezone = datetime.strptime(thedatetime, '%Y-%m-%d %H:%M:%S%z')
            except ValueError:
                print("DATE PROBLEM", s)
                raise SystemExit("ERROR:  invalid date format %s" % s)
    return with_timezone


def parse_datetime(date_string):
    """
    Given a string that should either represent an absolute date or an arbitrary date,
    parse this into a datetime object that is then used. Absolute dates should be in
    the format of YYYY-MM-DD HH:MM:SS+ZZZZ while arbitrary dates are of the format
    "+/-# day(s) [at HH:MM:SS]" where the last part is optional. If the time is
    omitted, then it uses midnight of whatever day was specified. Datetimes without
    timezones assume local timezone.

    Examples of allowed strings:
    2016-10-14
    2016-10-13 22:11:32+0100
    -1 day
    +2 days at 00:01:01

    :param date_string:
    :type date_string: str
    :return:
    :rtype: datetime
    """
    if date_string is None:
        return None
    elif isinstance(date_string, datetime):
        my_timezone = date_string.tzinfo
        if my_timezone is None:
            my_timezone = get_timezone()
            date_string = my_timezone.localize(date_string)

        return date_string
    elif not isinstance(date_string, str):
        raise TypeError(f'Invalid type, expected str, got {type(date_string)}')

    try:
        return datetime.strptime(date_string, '%Y-%m-%d %H:%M:%S%z')
    except ValueError:
        pass

    try:
        return get_timezone().localize(
            datetime.strptime(date_string, '%Y-%m-%d %H:%M:%S')
        )
        return
    except ValueError:
        pass

    try:
        return get_timezone().localize(
            datetime.strptime(date_string, '%Y-%m-%d').replace(
                hour=23,
                minute=59,
                second=59,
            )
        )
    except ValueError:
        pass

    m = re.search(
        '([+|-][0-9]+) (days|day) at ([0-2][0-9]):([0-5][0-9]):([0-5][0-9])',
        date_string
    )
    if m is not None:
        hour = int(m.group(3))
        minu = int(m.group(4))
        sec = int(m.group(5))
        days = int(m.group(1))
        return get_current_time().replace(
            hour=hour,
            minute=minu,
            second=sec,
            microsecond=0
        ) + timedelta(days=days)

    m = re.search('([+|-][0-9]+) (days|day)', date_string)
    if m is not None:
        days = int(m.group(1))
        return get_current_time().replace(
            hour=23,
            minute=59,
            second=59,
            microsecond=0
        ) + timedelta(days=days)

    raise ValueError("Invalid string for date parsing: " + str(date_string))


def get_current_semester() -> str:
    """
    Given today's date, generates a three character code that represents the semester to use for
    courses such that the first half of the year is considered "Spring" and the last half is
    considered "Fall". The "Spring" semester  gets an S as the first letter while "Fall" gets an
    F. The next two characters are the last two digits in the current year.
    """
    today = datetime.today()
    semester = "f" + str(today.year)[-2:]
    if today.month < 7:
        semester = "s" + str(today.year)[-2:]
    return semester
