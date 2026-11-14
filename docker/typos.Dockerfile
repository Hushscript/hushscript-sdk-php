FROM python:3.15-slim-bookworm@sha256:96599df822d0e1c6bb700014a05c7f3c8cabc6e0f7ff3c4f9f69cee033b4b5d9
COPY typos-requirements.txt /tmp/typos-requirements.txt
RUN pip install --no-cache-dir --disable-pip-version-check --require-hashes -r /tmp/typos-requirements.txt \
  && rm /tmp/typos-requirements.txt \
  && groupadd --gid 10001 dev && useradd --uid 10001 --gid 10001 --no-create-home --shell /usr/sbin/nologin dev
USER 10001:10001
WORKDIR /work
ENTRYPOINT ["typos"]
