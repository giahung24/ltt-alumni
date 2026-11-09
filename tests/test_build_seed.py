import tempfile
import unittest
from pathlib import Path

from scripts.build_seed import build_seed_sql, resolve_photo_filename


class BuildSeedTests(unittest.TestCase):
    def test_resolve_photo_from_picture_link_and_backup_photo(self):
        with tempfile.TemporaryDirectory() as tmp:
            img_dir = Path(tmp)
            (img_dir / "1abcDEF.jpeg").write_bytes(b"jpg")
            legacy = {"picture_link": "https://drive.google.com/open?id=1abcDEF"}
            self.assertEqual(resolve_photo_filename(legacy, img_dir), "1abcDEF.jpeg")
            self.assertIsNone(resolve_photo_filename({"picture_link": "https://drive.google.com/open?id=missing"}, img_dir))
            self.assertEqual(resolve_photo_filename({"photo": "static/img/u_1700000000_deadbeef.jpg"}, img_dir),
                             "u_1700000000_deadbeef.jpg")
            self.assertIsNone(resolve_photo_filename({"photo": "static/img/default.jpg"}, img_dir))

    def test_legacy_sorted_by_timestamp_emails_normalized_and_escaped(self):
        payload = {"entries": [
            {"timestamp": "2020-05-01 12:00:00", "fullname": "Bui Van B", "year": 2015, "picture_link": "",
             "education": "2015-2019: L'Université d'Angers", "work": "C:\\temp", "email": "  BUI.B@Example.com "},
            {"timestamp": "2019-11-14 10:56:01", "fullname": "Nguyen Minh A", "year": 2010, "picture_link": "",
             "education": "2010-2014: Can Tho", "work": "Dev", "email": "NGUYEN.A@Example.com"},
        ]}
        with tempfile.TemporaryDirectory() as tmp:
            sql = build_seed_sql(payload, Path(tmp))
        self.assertTrue(sql.startswith("SET NAMES utf8mb4;"))
        self.assertLess(sql.find("nguyen.a@example.com"), sql.find("bui.b@example.com"))
        self.assertIn("L''Université d''Angers", sql)
        self.assertIn("'C:\\\\temp'", sql)
        self.assertNotIn("`id`", sql)

    def test_backup_restore_preserves_id_and_is_deleted(self):
        payload = {"exported_at": "2026-11-08", "entries": [
            {"id": 42, "timestamp": "2026-11-08 09:00:00", "fullname": "Tran C", "year": 2018,
             "education": "2018-2022: Paris", "work": "Engineer", "email": "tran.c@example.com",
             "photo": "static/img/default.jpg", "is_deleted": 1},
        ]}
        with tempfile.TemporaryDirectory() as tmp:
            sql = build_seed_sql(payload, Path(tmp))
        self.assertIn("(`id`, `email`, `fullname`, `graduation_year`, `education`, `work`, `photo_filename`, `submitted_at`, `is_deleted`)", sql)
        self.assertIn("(42, 'tran.c@example.com'", sql)
        self.assertIn(", NULL, '2026-11-08 09:00:00', 1);", sql)


class BuildSeedEdgeCaseTests(unittest.TestCase):
    def test_drive_id_formats(self):
        with tempfile.TemporaryDirectory() as tmp:
            img_dir = Path(tmp)
            (img_dir / "1abc.jpg").write_bytes(b"x")
            for link in ("https://drive.google.com/open?authuser=0&id=1abc",
                         "https://drive.google.com/file/d/1abc/view",
                         "https://drive.google.com/open?id=1abc#view"):
                self.assertEqual(resolve_photo_filename({"picture_link": link}, img_dir), "1abc.jpg", link)

    def test_none_fields_newlines_and_blank_work(self):
        payload = {"entries": [
            {"timestamp": "2020-01-01 00:00:00", "fullname": None, "year": "2012", "education": "a\nb\r\nc",
             "work": "   ", "email": None},
        ]}
        with tempfile.TemporaryDirectory() as tmp:
            sql = build_seed_sql(payload, Path(tmp))
        self.assertNotIn("'none'", sql)
        self.assertIn("SET sql_mode = '';", sql)
        self.assertIn("('', '', 2012, 'a\\nb\\r\\nc', NULL, NULL, '2020-01-01 00:00:00', 0);", sql)
        self.assertEqual(sql.count("\n"), 4)

    def test_backup_sorted_by_id(self):
        base = {"timestamp": "2026-01-01 00:00:00", "fullname": "X", "year": 2010,
                "education": "e", "work": "w", "photo": "static/img/default.jpg"}
        payload = {"entries": [dict(base, id=9, email="b@x"), dict(base, id=3, email="a@x")]}
        with tempfile.TemporaryDirectory() as tmp:
            sql = build_seed_sql(payload, Path(tmp))
        self.assertLess(sql.find("(3, 'a@x'"), sql.find("(9, 'b@x'"))


if __name__ == "__main__":
    unittest.main()
