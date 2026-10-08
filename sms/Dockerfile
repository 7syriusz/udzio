FROM python:3.12-slim
WORKDIR /lab
COPY server /lab/server
RUN useradd --uid 10001 --create-home lab && mkdir /lab/runtime && chown lab:lab /lab/runtime
USER lab
EXPOSE 8787
CMD ["python3", "server/app.py", "--host", "0.0.0.0"]
