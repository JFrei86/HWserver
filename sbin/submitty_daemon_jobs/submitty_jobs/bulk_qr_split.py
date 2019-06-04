#!/usr/bin/env python3

"""Split PDFS by QR code and move images and PDFs to correct folder."""

import json
import os
import traceback
import sys
import numpy

# try importing required modules
try:
    from PyPDF2 import PdfFileReader, PdfFileWriter
    from pdf2image import convert_from_bytes
    import pyzbar.pyzbar as pyzbar
    from pyzbar.pyzbar import ZBarSymbol
    import cv2
except ImportError:
    print("One or more required python modules not installed correctly")
    traceback.print_exc()
    sys.exit(1)


def main(args):
    """Scan through PDF and split PDF and images."""
    filename = args[0]
    split_path = args[1]
    qr_prefix = args[2]
    qr_suffix = args[3]
    log_file = args[4]
    try:
        os.chdir(split_path)
        pdfPages = PdfFileReader(filename)
        pdf_writer = PdfFileWriter()
        i = cover_index = id_index = 0
        page_count = 1
        prev_file = ''
        data = []
        output = {}
        for page_number in range(pdfPages.numPages):
            # convert pdf to series of images for scanning
            page = convert_from_bytes(
                open(filename, 'rb').read(),
                first_page=page_number+1, last_page=page_number+2)[0]
            # increase contrast of image for better QR decoding
            cv_img = numpy.array(page)
            mask = cv2.inRange(cv_img, (0, 0, 0), (200, 200, 200))
            inverted = 255 - cv2.cvtColor(mask, cv2.COLOR_GRAY2BGR)
            # decode img - only look for QR codes
            val = pyzbar.decode(inverted, symbols=[ZBarSymbol.QRCODE])
            if val != []:
                # found a new qr code, split here
                # convert byte literal to string
                data = val[0][0].decode("utf-8")
                log_file.write("\tFound a QR code with value " + data +
                               " on page " + str(page_number) + "\n")
                if data == "none":  # blank exam with 'none' qr code
                    data = "BLANK EXAM"
                else:
                    pre = data[0:len(qr_prefix)]
                    suf = data[(len(data)-len(qr_suffix)):len(data)]
                    if qr_prefix != '' and pre == qr_prefix:
                        data = data[len(qr_prefix):]
                    if qr_suffix != '' and suf == qr_suffix:
                        data = data[:-len(qr_suffix)]
                cover_index = i
                cover_filename = '{}_{}_cover.pdf'.format(filename[:-4], i)
                output_filename = '{}_{}.pdf'.format(filename[:-4], cover_index)

                output[output_filename] = {}
                output[output_filename]['id'] = data
                # save pdf
                if i != 0 and prev_file != '':
                    output[prev_file]['page_count'] = page_count
                    with open(prev_file, 'wb') as out:
                        pdf_writer.write(out)

                    page.save('{}.jpg'.format(prev_file[:-4]), "JPEG", quality=100)

                if id_index == 1:
                    # correct first pdf's page count and print file
                    output[prev_file]['page_count'] = page_count
                    with open(prev_file, 'wb') as out:
                        pdf_writer.write(out)

                    page.save('{}.jpg'.format(prev_file[:-4]), "JPEG", quality=100)

                # start a new pdf and grab the cover
                cover_writer = PdfFileWriter()
                pdf_writer = PdfFileWriter()
                cover_writer.addPage(pdfPages.getPage(i))
                pdf_writer.addPage(pdfPages.getPage(i))

                # save cover
                with open(cover_filename, 'wb') as out:
                    cover_writer.write(out)

                # save cover image
                page.save('{}.jpg'.format(cover_filename[:-4]), "JPEG", quality=100)

                id_index += 1
                page_count = 1
                prev_file = output_filename
            else:
                # add pages to current split_pdf
                page_count += 1
                pdf_writer.addPage(pdfPages.getPage(i))
            i += 1

        log_file.write("\tFinished splitting into {} files\n".format(cover_index))

        # save whatever is left
        output_filename = '{}_{}.pdf'.format(filename[:-4], cover_index)
        output[output_filename]['id'] = data
        output[output_filename]['page_count'] = page_count

        with open(output_filename, 'wb') as out:
            pdf_writer.write(out)

        if not os.path.exists('decoded.json'):
            # write json to file for parsing page counts and decoded ids later
            with open('decoded.json', 'w') as out:
                json.dump(output, out, sort_keys=True, indent=4)
        else:
            with open('decoded.json') as file:
                prev_data = json.load(file)

            prev_data.update(output)

            with open('decoded.json', 'w') as out:
                json.dump(prev_data, out)

    except Exception:
        msg = "Failed when splitting pdf " + filename
        print(msg)
        traceback.print_exc()
        log_file.write(msg + "\n")
        log_file.write(traceback.format_exc())
        sys.exit(1)


if __name__ == "__main__":
    main()
