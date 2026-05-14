#pragma once
#include <string>

class IDisplay {
public:
    virtual ~IDisplay() = default;
    virtual void showMessage(const std::string& line1, const std::string& line2 = "") = 0;
    virtual void clear() = 0;
};
