# Chạy Record to Quiz trên Windows bằng WSL2

Hướng dẫn này dành cho Windows và đi từ cài môi trường đến chạy thử. Dự án
gồm PHP điều phối, Python cung cấp VAD/Whisper qua HTTP, và Gemini tạo JSON
tóm tắt/quiz.

> PHP chạy song song và chế độ live dùng `pcntl_fork()`, không hoạt động với
> PHP native trên Windows. Hãy chạy các lệnh PHP trong Ubuntu/WSL2.

## Bước 1: Cài WSL2 và Ubuntu

Mở **PowerShell bằng quyền Administrator** rồi chạy:

```powershell
wsl --install -d Ubuntu
```

Khởi động lại máy nếu Windows yêu cầu. Mở ứng dụng **Ubuntu** từ Start Menu,
tạo Linux username/password. Mật khẩu không hiện ký tự khi gõ là bình thường.

Kiểm tra đang chạy WSL2 trong PowerShell:

```powershell
wsl -l -v
```

Cột VERSION của Ubuntu nên là `2`. Nếu đang là `1`, chạy:

```powershell
wsl --set-version Ubuntu 2
```

## Bước 2: Cài PHP, FFmpeg và Python trong Ubuntu

Trong terminal Ubuntu:

```sh
sudo apt update
sudo apt install -y php-cli php-curl php-mbstring ffmpeg python3-venv python3-pip
```

Kiểm tra PHP và extension:

```sh
php -v
php -m | grep -E 'curl|pcntl'
ffmpeg -version
```

PHP cần phiên bản 8.1 trở lên. Kết quả `php -m` cần có cả `curl` và `pcntl`.
Nếu chưa thấy `pcntl`, hãy kiểm tra PHP đang chạy trong Ubuntu/WSL chứ không
phải PHP native Windows:

```sh
which php
php --ini
```

## Bước 3: Tìm file audio để thử

Ví dụ dưới đây giả sử file nằm tại:

```text
D:\University\semester7\DACN\record-to-quiz\recordings\sample.wav
```

Đường dẫn tương ứng trong Ubuntu/WSL là:

```text
/mnt/d/University/semester7/DACN/record-to-quiz/recordings/sample.wav
```

Nếu chưa có thư mục `recordings`, hãy tạo nó trong project hoặc đổi đường dẫn
ở các lệnh sau thành vị trí audio thật. Nên bắt đầu với file WAV ngắn có
giọng nói rõ ràng. File im lặng hoặc chỉ có tiếng nhạc có thể không được VAD
nhận diện là lời nói.

Kiểm tra file:

```sh
cd /mnt/d/University/semester7/DACN/record-to-quiz
ls -lh recordings/sample.wav
ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 recordings/sample.wav
```

## Bước 4: Tạo Gemini API key

Tạo API key trong trang Google AI Studio, sau đó đặt biến môi trường trong
terminal Ubuntu sẽ chạy PHP:

```sh
export GEMINI_API_KEY='DAN_API_KEY_CUA_BAN_VAO_DAY'
```

Không ghi API key vào README, source code hoặc file được commit. Mỗi terminal
Ubuntu mới cần đặt lại biến này. Có thể kiểm tra rằng biến đã được đặt mà
không in giá trị bí mật:

```sh
test -n "$GEMINI_API_KEY" && echo "Gemini key is set"
```

## Bước 5: Cài và chạy Python ML service

Mở **terminal Ubuntu thứ nhất**:

```sh
cd /mnt/d/University/semester7/DACN/record-to-quiz/ml-service
python3 -m venv .venv
source .venv/bin/activate
python -m pip install --upgrade pip
pip install -r requirements.txt
```

Khởi động service bằng cấu hình CPU để bắt đầu thử:

```sh
WHISPER_DEVICE=cpu \
WHISPER_MODEL=small \
WHISPER_COMPUTE_TYPE=int8 \
uvicorn main:app --host 0.0.0.0 --port 8000
```

Lần đầu, Python có thể tải model Whisper và model Silero VAD; việc này cần
kết nối Internet và có thể mất thời gian/dung lượng. Để ưu tiên độ chính xác
tiếng Việt trên CPU, sau khi thử kết nối có thể đổi `WHISPER_MODEL=medium`.
Máy NVIDIA CUDA có thể dùng cấu hình `large-v3`, `cuda`, `float16` theo
[hướng dẫn ML service](../ml-service/README.md).

Giữ terminal này mở khi chạy PHP. Khi thấy Uvicorn báo service đang chạy ở
cổng `8000`, chuyển sang terminal Ubuntu thứ hai.

## Bước 6: Kiểm tra Python service và audio

Trong **terminal Ubuntu thứ hai**:

```sh
cd /mnt/d/University/semester7/DACN/record-to-quiz
curl http://127.0.0.1:8000/health
```

Kết quả cần là JSON có `"status":"ok"`, kèm model/device/compute type.

Kiểm tra VAD trước:

```sh
curl -X POST --data-binary @recordings/sample.wav \
  -H "Content-Type: application/octet-stream" \
  http://127.0.0.1:8000/vad
```

Nếu file có lời nói, kết quả thường có `segments` với các mốc `start`/`end`.
Kiểm tra Whisper trực tiếp:

```sh
curl -X POST --data-binary @recordings/sample.wav \
  -H "Content-Type: application/octet-stream" \
  "http://127.0.0.1:8000/transcribe?language=vi"
```

Kết quả có `text`, `chunks`, và `timings_ms`. Nếu nhận lỗi decode, kiểm tra
file bằng `ffprobe` hoặc chuyển file sang WAV PCM bằng FFmpeg.

## Bước 7: Kiểm tra cú pháp PHP

Trong terminal Ubuntu thứ hai:

```sh
cd /mnt/d/University/semester7/DACN/record-to-quiz/php-service
find . -name '*.php' -print0 | xargs -0 -n1 php -l
```

Mỗi file cần báo `No syntax errors detected`.

## Bước 8: Chạy xử lý file đã ghi (upload mode)

Đặt các biến môi trường trong terminal Ubuntu thứ hai:

```sh
export GEMINI_API_KEY='DAN_API_KEY_CUA_BAN_VAO_DAY'
export WHISPER_URL='http://127.0.0.1:8000'
```

Chạy file mẫu:

```sh
php bin/record-to-quiz.php ../recordings/sample.wav --metrics metrics.json
```

Lệnh này in kết quả JSON ra terminal và ghi metrics vào `metrics.json` trong
thư mục `php-service`. Để lưu JSON kết quả ra file riêng:

```sh
php bin/record-to-quiz.php ../recordings/sample.wav \
  --metrics metrics.json \
  --output output.json
```

Metrics gồm thời gian tổng, VAD, STT, LLM, số lần thử lại, số token và số lần
gọi Gemini. Nếu muốn tính chi phí ước lượng, đặt đơn giá cho model đang dùng:

```sh
export GEMINI_INPUT_USD_PER_MILLION='DON_GIA_INPUT'
export GEMINI_OUTPUT_USD_PER_MILLION='DON_GIA_OUTPUT'
```

Nếu chưa đặt cả hai đơn giá, chi phí được trả về là `null`; số token vẫn được
ghi nếu Gemini trả metadata sử dụng.

## Bước 9: Chạy live simulation

Live mode mô phỏng ghi âm bằng cách phát file qua FFmpeg theo thời gian thực;
nó không thu âm từ microphone. Chọn file dài hơn 60 giây để kiểm tra soft-cut:

```sh
php bin/record-to-quiz.php --live --workers=2 --queue=4 \
  ../recordings/sample.wav \
  --metrics live-metrics.json \
  --output live-output.json
```

Chunk được ưu tiên cắt tại khoảng lặng sau mốc 60 giây và bị cắt cứng tối đa
ở 75 giây. Kết quả được ghép lại theo thứ tự chunk trước khi gửi cho Gemini.

## Bước 10: Parallel mode và giới hạn hiện tại

Có thể gọi PHP parallel mode với nhiều file:

```sh
php bin/record-to-quiz.php --parallel --workers=2 \
  ../recordings/sample.wav ../recordings/another.wav
```

Số worker hiệu dụng là giới hạn nhỏ hơn giữa `--workers` và
`WHISPER_MAX_CONCURRENCY`. Mặc định giới hạn này là `1`, vì Python service
hiện có một model Whisper xử lý đồng bộ. Vì vậy lệnh trên có thể chỉ chạy một
worker và không nhanh hơn.

**Lưu ý:** parallel mode hiện phân công các file đầu vào cho worker; một file
dài được VAD/chunk/transcribe tuần tự bên trong Python service. Parallel
transcription các chunk của cùng một file và so sánh benchmark thực sự vẫn là
phần cần hoàn thiện tiếp theo.

## Lỗi thường gặp

- `Connection refused`: kiểm tra Uvicorn còn chạy và
  `WHISPER_URL=http://127.0.0.1:8000`.
- `pcntl_fork` không khả dụng: chạy PHP trong Ubuntu/WSL2, kiểm tra
  `which php` và `php -m | grep pcntl`.
- Không đọc được file: kiểm tra đường dẫn Linux (`/mnt/d/...`), phân biệt
  chữ hoa/chữ thường, và thử `ls -lh <đường-dẫn>`.
- VAD không tìm thấy giọng nói: chọn file có tiếng nói rõ; file nhạc/im lặng
  không tạo transcript hợp lệ.
- Gemini lỗi key/quota/model: kiểm tra `GEMINI_API_KEY`, giới hạn quota và
  model được bật cho API key.
- FFmpeg lỗi trong live mode: chạy `ffmpeg -version` và thử giải mã file bằng
  `ffprobe` trước.
