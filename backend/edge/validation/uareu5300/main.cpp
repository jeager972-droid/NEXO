/**
 * =============================================================================
 * uareu5300_validation.cpp — Programa independiente de validación SDK U.are.U.
 * =============================================================================
 * RESPONSABILIDAD:
 *   Validar el SDK DigitalPersona U.are.U 5300 fuera de NEXO. Mide tiempos de
 *   captura, extracción FMD, comparación 1:1 e identificación 1:N en PC y
 *   Raspberry Pi. No depende de SQLite ni de NEXO.
 *
 * USO:
 *   ./uareu5300_validation
 *   Sigue las instrucciones para enrolar 3 dedos y luego identificar.
 */

#include <dpfpdd.h>
#include <dpfj.h>

#include <iostream>
#include <vector>
#include <string>
#include <cstring>
#include <chrono>
#include <csignal>
#include <cmath>
#include <algorithm>
#include <dlfcn.h>
#include <libgen.h>

static volatile std::sig_atomic_t g_stop = 0;
static DPFPDD_DEV g_dev = nullptr;

void signalHandler(int) {
    g_stop = 1;
    if (g_dev) dpfpdd_cancel(g_dev);
}

static std::string dpErrorString(int err) {
    return "0x" + std::to_string(static_cast<unsigned int>(err));
}

// Undocumented SDK helper: sets the directory where .dat/.lic files are located.
extern "C" int dpfpdd_set_classifier_path(const char* path);

static bool configureUareuEnvironment() {
    Dl_info info;
    if (dladdr((void*)dpfpdd_init, &info) == 0 || !info.dli_fname) {
        return false;
    }
    char pathBuf[4096];
    strncpy(pathBuf, info.dli_fname, sizeof(pathBuf) - 1);
    pathBuf[sizeof(pathBuf) - 1] = '\0';
    const char* libDir = dirname(pathBuf);

    if (libDir && *libDir) {
        dpfpdd_set_classifier_path(libDir);
    }

    const char* plugins[] = {
        "libdpfpdd5000.so",
        "libdpfpdd_4k.so",
        "libdpfpdd7k.so",
        "libdpfpdd_ptapi.so",
        "libnex_sdk.so",
        "libdpfr6.so",
        "libdpfr7.so",
        "libtfm.so",
        nullptr
    };
    for (const char** p = plugins; *p; ++p) {
        dlopen(*p, RTLD_NOW | RTLD_GLOBAL);
    }
    return true;
}

static bool captureFinger(DPFPDD_DEV dev, std::vector<unsigned char>& fid, int timeoutMs = 10000) {
    DPFPDD_CAPTURE_PARAM capParam{};
    capParam.size = sizeof(capParam);
    capParam.image_fmt = DPFPDD_IMG_FMT_ISOIEC19794;
    capParam.image_proc = DPFPDD_IMG_PROC_DEFAULT;
    capParam.image_res = 0;

    std::vector<unsigned char> imageData(512 * 1024);
    unsigned int imageSize = static_cast<unsigned int>(imageData.size());
    DPFPDD_CAPTURE_RESULT capResult{};
    capResult.size = sizeof(capResult);

    auto t0 = std::chrono::steady_clock::now();
    int rc = dpfpdd_capture(dev, &capParam, static_cast<unsigned int>(timeoutMs),
                            &capResult, &imageSize, imageData.data());
    if (rc == DPFPDD_E_MORE_DATA && imageSize > 0) {
        imageData.resize(imageSize);
        rc = dpfpdd_capture(dev, &capParam, static_cast<unsigned int>(timeoutMs),
                            &capResult, &imageSize, imageData.data());
    }
    auto t1 = std::chrono::steady_clock::now();
    auto ms = std::chrono::duration_cast<std::chrono::milliseconds>(t1 - t0).count();
    std::cout << "  [capture] " << ms << " ms (rc=" << dpErrorString(rc) << ")\n";

    if (rc != DPFPDD_SUCCESS || !capResult.success || capResult.quality != DPFPDD_QUALITY_GOOD) {
        std::cout << "  Capture failed/quality rc=" << dpErrorString(rc)
                  << " success=" << capResult.success
                  << " quality=" << capResult.quality << "\n";
        return false;
    }
    imageData.resize(imageSize);
    fid = std::move(imageData);
    return true;
}

static bool createFmd(const std::vector<unsigned char>& fid, std::vector<unsigned char>& fmd) {
    fmd.assign(MAX_FMD_SIZE, 0);
    unsigned int fmdSize = MAX_FMD_SIZE;
    auto t0 = std::chrono::steady_clock::now();
    int rc = dpfj_create_fmd_from_fid(DPFJ_FID_ISO_19794_4_2005,
                                      fid.data(), static_cast<unsigned int>(fid.size()),
                                      DPFJ_FMD_ISO_19794_2_2005,
                                      fmd.data(), &fmdSize);
    auto t1 = std::chrono::steady_clock::now();
    auto ms = std::chrono::duration_cast<std::chrono::milliseconds>(t1 - t0).count();
    std::cout << "  [extract] " << ms << " ms (rc=" << dpErrorString(rc) << ")\n";
    if (rc != DPFJ_SUCCESS) return false;
    fmd.resize(fmdSize);
    return true;
}

static bool selectEngine(DPFPDD_DEV dev) {
    int rc = dpfj_select_engine(dev, DPFJ_ENGINE_DPFJ7);
    if (rc == DPFJ_E_NOT_IMPLEMENTED) {
        std::cout << "  FingerJet v7 not implemented, falling back to v6\n";
        rc = dpfj_select_engine(dev, DPFJ_ENGINE_DPFJ);
    }
    if (rc != DPFJ_SUCCESS) {
        std::cout << "  Engine select failed: " << dpErrorString(rc) << "\n";
        return false;
    }
    std::cout << "  Engine selected\n";
    return true;
}

static float dissimilarityToScore(unsigned int score) {
    double d = static_cast<double>(score) / static_cast<double>(DPFJ_PROBABILITY_ONE);
    return static_cast<float>(std::max(0.0, 100.0 * (1.0 - d)));
}

static void benchmarkIdentify(const std::vector<std::vector<unsigned char>>& enrolledFmds) {
    if (enrolledFmds.size() < 2) {
        std::cout << "Benchmark skipped: need at least 2 enrolled FMDs\n";
        return;
    }
    // Use finger 0 as probe. Fill gallery with other fingers, match at the end.
    const auto& probeFmd = enrolledFmds[0];
    const unsigned int threshold = DPFJ_PROBABILITY_ONE / 100000;
    for (int N : {500, 1000, 1500}) {
        std::vector<unsigned char*> fmds;
        std::vector<unsigned int> fmdsSize;
        fmds.reserve(N);
        fmdsSize.reserve(N);
        for (int i = 0; i < N - 1; ++i) {
            size_t src = 1 + (i % (enrolledFmds.size() - 1));
            fmds.push_back(const_cast<unsigned char*>(enrolledFmds[src].data()));
            fmdsSize.push_back(static_cast<unsigned int>(enrolledFmds[src].size()));
        }
        fmds.push_back(const_cast<unsigned char*>(enrolledFmds[0].data()));
        fmdsSize.push_back(static_cast<unsigned int>(enrolledFmds[0].size()));

        DPFJ_CANDIDATE candidate{};
        candidate.size = sizeof(DPFJ_CANDIDATE);
        unsigned int candidateCnt = 1;
        auto t0 = std::chrono::steady_clock::now();
        int rc = dpfj_identify(DPFJ_FMD_ISO_19794_2_2005,
                               const_cast<unsigned char*>(probeFmd.data()), static_cast<unsigned int>(probeFmd.size()), 0,
                               DPFJ_FMD_ISO_19794_2_2005,
                               static_cast<unsigned int>(N),
                               fmds.data(), fmdsSize.data(),
                               threshold, &candidateCnt, &candidate);
        auto t1 = std::chrono::steady_clock::now();
        auto us = std::chrono::duration_cast<std::chrono::microseconds>(t1 - t0).count();
        std::cout << "[benchmark] N=" << N << " identify=" << us << " us"
                  << " rc=" << dpErrorString(rc) << " candidates=" << candidateCnt;
        if (rc == DPFJ_SUCCESS && candidateCnt > 0) {
            std::cout << " matched_idx=" << candidate.fmd_idx;
        }
        std::cout << "\n";
    }
}

int main() {
    std::signal(SIGINT, signalHandler);
    std::signal(SIGTERM, signalHandler);

    configureUareuEnvironment();

    std::cout << "U.are.U 5300 SDK validation\n";
    std::cout << "============================\n";

    int rc = dpfpdd_init();
    if (rc != DPFPDD_SUCCESS) {
        std::cerr << "dpfpdd_init failed: " << dpErrorString(rc) << "\n";
        return 1;
    }

    // Same pattern as UareUCaptureOnly/selection.c
    unsigned int devCnt = 1;
    std::vector<DPFPDD_DEV_INFO> devInfos(devCnt);
    for (auto& info : devInfos) info.size = sizeof(DPFPDD_DEV_INFO);

    rc = dpfpdd_query_devices(&devCnt, devInfos.data());
    if (rc == DPFPDD_E_MORE_DATA) {
        devInfos.resize(devCnt);
        for (auto& info : devInfos) info.size = sizeof(DPFPDD_DEV_INFO);
        rc = dpfpdd_query_devices(&devCnt, devInfos.data());
    }
    if (rc != DPFPDD_SUCCESS || devCnt == 0) {
        std::cerr << "query_devices failed: " << dpErrorString(rc) << "\n";
        dpfpdd_exit();
        return 1;
    }

    std::cout << "Found " << devCnt << " reader(s)\n";
    std::cout << "Opening: " << devInfos[0].name << "\n";

    rc = dpfpdd_open(devInfos[0].name, &g_dev);
    if (rc != DPFPDD_SUCCESS) {
        std::cerr << "dpfpdd_open failed: " << dpErrorString(rc) << "\n";
        dpfpdd_exit();
        return 1;
    }

    if (!selectEngine(g_dev)) {
        dpfpdd_close(g_dev);
        dpfpdd_exit();
        return 1;
    }

    // Enroll 3 fingers for the 1:N test
    std::vector<std::vector<unsigned char>> enrolledFmds;
    std::vector<std::string> fingerNames;
    for (int i = 1; i <= 3; ++i) {
        std::cout << "\nEnroll finger " << i << ": place the same finger 2-4 times. Press Ctrl+C to abort.\n";
        int startCount = 0;
        rc = dpfj_start_enrollment(DPFJ_FMD_ISO_19794_2_2005);
        if (rc != DPFJ_SUCCESS) {
            std::cerr << "start_enrollment failed: " << dpErrorString(rc) << "\n";
            break;
        }
        bool ready = false;
        while (!g_stop && startCount < 4) {
            std::cout << "  Place finger (enroll " << i << ", capture " << (startCount + 1) << ")\n";
            std::vector<unsigned char> fid;
            if (!captureFinger(g_dev, fid)) continue;
            std::vector<unsigned char> fmd;
            if (!createFmd(fid, fmd)) continue;
            rc = dpfj_add_to_enrollment(DPFJ_FMD_ISO_19794_2_2005,
                                        fmd.data(), static_cast<unsigned int>(fmd.size()), 0);
            if (rc == DPFJ_SUCCESS) { ready = true; break; }
            if (rc == DPFJ_E_MORE_DATA) { ++startCount; continue; }
            std::cerr << "  add_to_enrollment failed: " << dpErrorString(rc) << "\n";
            break;
        }
        std::vector<unsigned char> enrolled(MAX_FMD_SIZE, 0);
        unsigned int enrolledSize = MAX_FMD_SIZE;
        if (ready) {
            rc = dpfj_create_enrollment_fmd(enrolled.data(), &enrolledSize);
            dpfj_finish_enrollment();
            if (rc == DPFJ_SUCCESS) {
                enrolled.resize(enrolledSize);
                enrolledFmds.push_back(std::move(enrolled));
                fingerNames.push_back("finger_" + std::to_string(i));
                std::cout << "  Enrolled " << fingerNames.back() << " (size=" << enrolledSize << ")\n";
            } else {
                std::cerr << "  create_enrollment_fmd failed: " << dpErrorString(rc) << "\n";
            }
        } else {
            dpfj_finish_enrollment();
            std::cerr << "  Finger " << i << " enrollment not ready\n";
        }
    }

    if (enrolledFmds.empty()) {
        std::cerr << "No fingers enrolled, cannot continue\n";
        dpfpdd_close(g_dev);
        dpfpdd_exit();
        return 1;
    }

    // 1:1 compare first two enrolled templates
    if (enrolledFmds.size() >= 2) {
        unsigned int score = 0;
        auto t0 = std::chrono::steady_clock::now();
        int rc2 = dpfj_compare(DPFJ_FMD_ISO_19794_2_2005,
                               enrolledFmds[0].data(), static_cast<unsigned int>(enrolledFmds[0].size()), 0,
                               DPFJ_FMD_ISO_19794_2_2005,
                               enrolledFmds[1].data(), static_cast<unsigned int>(enrolledFmds[1].size()), 0,
                               &score);
        auto t1 = std::chrono::steady_clock::now();
        auto ms = std::chrono::duration_cast<std::chrono::milliseconds>(t1 - t0).count();
        std::cout << "\n[compare] " << ms << " ms rc=" << dpErrorString(rc2)
                  << " score=" << score << " match%=" << dissimilarityToScore(score) << "\n";
    }

    // Benchmark 1:N identify scaling
    benchmarkIdentify(enrolledFmds);

    // Build 1:N arrays
    std::vector<unsigned char*> fmds;
    std::vector<unsigned int> fmdsSize;
    for (auto& f : enrolledFmds) {
        fmds.push_back(f.data());
        fmdsSize.push_back(static_cast<unsigned int>(f.size()));
    }

    // Identification loop
    while (!g_stop) {
        std::cout << "\nPlace any enrolled finger for identify (or Ctrl+C to exit)\n";
        std::vector<unsigned char> probeFid;
        if (!captureFinger(g_dev, probeFid)) continue;
        std::vector<unsigned char> probeFmd;
        if (!createFmd(probeFid, probeFmd)) continue;

        DPFJ_CANDIDATE candidate{};
        candidate.size = sizeof(DPFJ_CANDIDATE);
        unsigned int candidateCnt = 1;
        auto t0 = std::chrono::steady_clock::now();
        rc = dpfj_identify(DPFJ_FMD_ISO_19794_2_2005,
                           probeFmd.data(), static_cast<unsigned int>(probeFmd.size()), 0,
                           DPFJ_FMD_ISO_19794_2_2005,
                           static_cast<unsigned int>(enrolledFmds.size()),
                           fmds.data(), fmdsSize.data(),
                           DPFJ_PROBABILITY_ONE / 100, &candidateCnt, &candidate);
        auto t1 = std::chrono::steady_clock::now();
        auto ms = std::chrono::duration_cast<std::chrono::milliseconds>(t1 - t0).count();

        if (rc == DPFJ_SUCCESS && candidateCnt > 0) {
            unsigned int cmpScore = 0;
            dpfj_compare(DPFJ_FMD_ISO_19794_2_2005,
                         probeFmd.data(), static_cast<unsigned int>(probeFmd.size()), 0,
                         DPFJ_FMD_ISO_19794_2_2005,
                         enrolledFmds[candidate.fmd_idx].data(),
                         static_cast<unsigned int>(enrolledFmds[candidate.fmd_idx].size()), 0,
                         &cmpScore);
            std::cout << "[identify] " << ms << " ms matched=" << fingerNames[candidate.fmd_idx]
                      << " index=" << candidate.fmd_idx
                      << " compare_score=" << cmpScore
                      << " match%=" << dissimilarityToScore(cmpScore) << "\n";
        } else {
            std::cout << "[identify] " << ms << " ms NO MATCH (rc=" << dpErrorString(rc)
                      << " candidates=" << candidateCnt << ")\n";
        }
    }

    dpfpdd_close(g_dev);
    dpfpdd_exit();
    std::cout << "\nValidation complete\n";
    return 0;
}
